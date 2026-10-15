<?php

namespace SoftArtisan\Vanguard;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

class Vanguard
{
    /**
     * Whether Vanguard should register its routes.
     */
    public static bool $registersRoutes = true;

    /**
     * Whether Vanguard should run its migrations automatically.
     */
    public static bool $runsMigrations = true;

    /**
     * The callback used to authenticate Vanguard users.
     */
    public static ?Closure $authUsing = null;

    /**
     * The callback used to name the operator behind a restore.
     */
    public static ?Closure $restoreActorUsing = null;

    /**
     * Get or set the Vanguard dashboard path.
     *
     * When called without arguments, returns the current configured path.
     * When called with a path, updates the config and returns the instance for chaining.
     *
     * @param  string|null  $path  Dashboard URL prefix (e.g. 'admin/backups')
     * @return string|static Current path string when reading; static instance when writing
     */
    public static function path(?string $path = null): string|static
    {
        if ($path === null) {
            return config('vanguard.path', 'vanguard');
        }
        config(['vanguard.path' => $path]);

        return new static;
    }

    /**
     * Configure the Vanguard dashboard domain.
     *
     * @param  string  $domain  Fully-qualified domain (e.g. 'tools.acme.com')
     */
    public static function domain(string $domain): static
    {
        config(['vanguard.domain' => $domain]);

        return new static;
    }

    /**
     * Set the callback that should be used to authenticate Vanguard users.
     *
     * Usage in AppServiceProvider::boot():
     *   Vanguard::auth(fn ($request) => auth()->check());
     *
     * @param  Closure  $callback  Receives an Illuminate\Http\Request; return true to grant access
     */
    public static function auth(Closure $callback): static
    {
        static::$authUsing = $callback;

        return new static;
    }

    /**
     * Set the callback that names the operator behind a restore.
     *
     * The package cannot presume the host application's user model — an ops
     * guard, an API token, a console operator — so the application decides
     * what identifies the person who asked.
     */
    public static function restoreActor(Closure $callback): static
    {
        static::$restoreActorUsing = $callback;

        return new static;
    }

    /**
     * Resolve who is acting, or null when nobody can be named.
     */
    public static function actor(): ?string
    {
        $actor = static::$restoreActorUsing
            ? call_user_func(static::$restoreActorUsing)
            : auth()->user()?->getAuthIdentifier();

        return $actor === null ? null : (string) $actor;
    }

    /**
     * The connection Vanguard's own tables live on.
     *
     * stancl/tenancy swaps the default database connection for the duration
     * of a tenancy window, and a vanguard_* query that lands in a tenant
     * database finds no such table. Every read and write of vanguard_backups
     * and vanguard_restores resolves its connection here.
     *
     * Never the literal 'central': on this product's production installs the
     * central connection is the application's default one (mysql), and no
     * connection named 'central' exists. `?:` rather than config()'s second
     * argument, because a key that is present but null returns null, not the
     * fallback.
     */
    public static function centralConnection(): string
    {
        return config('tenancy.database.central_connection') ?: config('database.default');
    }

    /** Cloudflare R2 data location jurisdictions that have an endpoint of their own. */
    public const R2_JURISDICTIONS = ['eu', 'fedramp'];

    /**
     * Whether vanguard.r2 holds enough to declare an R2 disk: a bucket, and
     * an account id or an explicit endpoint. Empty by default.
     */
    public static function r2Configured(): bool
    {
        $filled = fn (string $key): bool => is_string($value = config("vanguard.r2.{$key}")) && trim($value) !== '';

        return $filled('bucket') && ($filled('account_id') || $filled('endpoint'));
    }

    /**
     * The name of the disk Vanguard declares for R2 (vanguard.r2.disk).
     */
    public static function r2DiskName(): string
    {
        $name = config('vanguard.r2.disk');

        return is_string($name) && trim($name) !== '' ? trim($name) : 'vanguard-r2';
    }

    /**
     * An S3 disk configuration aimed at Cloudflare R2, built from vanguard.r2.
     *
     * Region 'auto' and a path-style endpoint, as R2 expects. No ACL nor
     * visibility: R2 does not implement object ACLs. Checksums are computed
     * and validated only when an operation requires them — recent AWS SDKs
     * (3.337+) add CRC checksum headers to every upload by default, which
     * S3-compatible services do not all accept; older SDKs ignore both keys.
     *
     * Public so that an application can declare the disk itself, e.g. under
     * another name from a service provider.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException On an unknown jurisdiction — guessing
     *                                  would send the archives to another one
     */
    public static function r2DiskConfig(): array
    {
        return [
            'driver' => 's3',
            'key' => config('vanguard.r2.access_key_id'),
            'secret' => config('vanguard.r2.secret_access_key'),
            'region' => 'auto',
            'bucket' => config('vanguard.r2.bucket'),
            'endpoint' => static::r2Endpoint(),
            'use_path_style_endpoint' => true,
            'throw' => true,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ];
    }

    /**
     * The R2 endpoint: vanguard.r2.endpoint when set, otherwise
     * https://<account_id>[.<jurisdiction>].r2.cloudflarestorage.com.
     *
     * @throws InvalidArgumentException On an unknown jurisdiction
     */
    public static function r2Endpoint(): ?string
    {
        $endpoint = config('vanguard.r2.endpoint');

        if (is_string($endpoint) && trim($endpoint) !== '') {
            return rtrim(trim($endpoint), '/');
        }

        $account = config('vanguard.r2.account_id');

        if (! is_string($account) || trim($account) === '') {
            return null;
        }

        $jurisdiction = config('vanguard.r2.jurisdiction');
        $jurisdiction = is_string($jurisdiction) ? strtolower(trim($jurisdiction)) : '';

        if ($jurisdiction !== '' && ! in_array($jurisdiction, self::R2_JURISDICTIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                '[Vanguard] Unknown vanguard.r2.jurisdiction [%s] (VANGUARD_R2_JURISDICTION): expected %s, or nothing for the default.',
                $jurisdiction,
                implode(' or ', self::R2_JURISDICTIONS),
            ));
        }

        $host = trim($account).($jurisdiction === '' ? '' : ".{$jurisdiction}");

        return "https://{$host}.r2.cloudflarestorage.com";
    }

    /**
     * Why the R2 disk cannot work on this host, or null.
     *
     * Only for the disk Vanguard declares for R2 (vanguard.r2): the S3
     * Flysystem adapter is a suggested dependency, and without it Laravel
     * fails with a bare "class not found". Other disks keep their own errors.
     */
    public static function r2DiskProblem(string $disk): ?string
    {
        if (! static::r2Configured() || $disk !== static::r2DiskName()) {
            return null;
        }

        if (config("filesystems.disks.{$disk}.driver") !== 's3') {
            return null;
        }

        if (class_exists(AwsS3V3Adapter::class)) {
            return null;
        }

        return "Disk [{$disk}] is Cloudflare R2, reached through the S3 API, and the S3 adapter is not installed: "
            .'run composer require league/flysystem-aws-s3-v3.';
    }

    /**
     * Declare the R2 disk in filesystems.disks, when R2 is configured and no
     * disk of that name exists. Called by the service provider at register.
     *
     * @return string|null The disk name declared, or null when nothing was
     *
     * @throws InvalidArgumentException On an unknown jurisdiction
     */
    public static function registerR2Disk(): ?string
    {
        if (! static::r2Configured()) {
            return null;
        }

        $name = static::r2DiskName();

        if (config("filesystems.disks.{$name}") !== null) {
            return null;
        }

        config(["filesystems.disks.{$name}" => static::r2DiskConfig()]);

        return $name;
    }

    /**
     * Determine if the given request can access the Vanguard dashboard.
     *
     * Delegates to the $authUsing callback if set, otherwise falls back to
     * checking that a user is authenticated.
     */
    public static function check(Request $request): bool
    {
        return (static::$authUsing ?? fn ($req) => $req->user() !== null)($request);
    }

    /**
     * Prevent Vanguard from registering its default routes.
     *
     * Use this when you want to define the routes manually in your application.
     */
    public static function ignoreRoutes(): static
    {
        static::$registersRoutes = false;

        return new static;
    }

    /**
     * Prevent Vanguard from running its migrations automatically.
     *
     * Use this when you publish and manage migrations yourself.
     */
    public static function ignoreMigrations(): static
    {
        static::$runsMigrations = false;

        return new static;
    }

    /**
     * Return the URL for a compiled Vanguard asset.
     *
     * Priority:
     *  1. If the asset has been published to public/vendor/vanguard/ (e.g. via
     *     `php artisan vendor:publish --tag=vanguard-assets`), return a plain
     *     asset() URL — served directly by nginx/Apache, zero PHP overhead.
     *  2. Otherwise fall back to the package route that serves the file from
     *     the vendor directory via AssetsController (one-time PHP hit, then
     *     browser-cached via ETag for the lifetime of the installed version).
     *
     * @param  string  $file  'vanguard.js' or 'vanguard.css'
     */
    public static function assetUrl(string $file): string
    {
        if (file_exists(public_path("vendor/vanguard/{$file}"))) {
            return asset("vendor/vanguard/{$file}");
        }

        return route('vanguard.assets', ['file' => $file]);
    }
}
