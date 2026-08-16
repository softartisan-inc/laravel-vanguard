<?php

namespace SoftArtisan\Vanguard;

use Closure;
use Illuminate\Http\Request;

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
