<?php

namespace SoftArtisan\Vanguard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SoftArtisan\Vanguard\Vanguard;
use Symfony\Component\HttpFoundation\Response;

class VanguardAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Vanguard::check($request)) {
            abort(403, 'Unauthorized access to Vanguard.');
        }

        return $next($request);
    }
}
