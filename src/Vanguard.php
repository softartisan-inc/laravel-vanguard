<?php

namespace SoftArtisan\Vanguard;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

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
     *
     * @var Closure|null
     */
    public static ?Closure $authUsing = null;

    /**
     * Get or set the Vanguard dashboard path.
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
     */
    public static function auth(Closure $callback): static
    {
        static::$authUsing = $callback;
        return new static;
    }

    /**
     * Determine if the given request can access the Vanguard dashboard.
     */
    public static function check(\Illuminate\Http\Request $request): bool
    {
        return (static::$authUsing ?? fn ($req) => $req->user() !== null)($request);
    }

    /**
     * Prevent Vanguard from registering its default routes.
     */
    public static function ignoreRoutes(): static
    {
        static::$registersRoutes = false;
        return new static;
    }

    /**
     * Prevent Vanguard from running its migrations.
     */
    public static function ignoreMigrations(): static
    {
        static::$runsMigrations = false;
        return new static;
    }
}
