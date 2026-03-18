<?php

namespace SoftArtisan\Vanguard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SoftArtisan\Vanguard\Vanguard;
use Symfony\Component\HttpFoundation\Response;

class VanguardAuthenticate
{
    /**
     * Handle an incoming request.
     *
     * Delegates the access decision to the configured Vanguard auth gate.
     * Aborts with a 403 if the request is not authorised.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Vanguard::check($request)) {
            abort(403, 'Unauthorized access to Vanguard.');
        }

        return $next($request);
    }
}
