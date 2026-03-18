<?php

namespace SoftArtisan\Vanguard\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \SoftArtisan\Vanguard\Vanguard path(string $path)
 * @method static \SoftArtisan\Vanguard\Vanguard domain(string $domain)
 * @method static \SoftArtisan\Vanguard\Vanguard auth(\Closure $callback)
 * @method static bool check(\Illuminate\Http\Request $request)
 * @method static \SoftArtisan\Vanguard\Vanguard ignoreRoutes()
 * @method static \SoftArtisan\Vanguard\Vanguard ignoreMigrations()
 *
 * @see \SoftArtisan\Vanguard\Vanguard
 */
class Vanguard extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return \SoftArtisan\Vanguard\Vanguard::class;
    }
}
