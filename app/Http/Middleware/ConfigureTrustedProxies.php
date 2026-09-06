<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigureTrustedProxies
{
    /**
     * Apply the cached deployment proxy list before Laravel resolves forwarded
     * headers. This keeps proxy behavior working when .env is not loaded after
     * configuration caching.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $proxies = config('deployment.trusted_proxies', []);
        $proxies = is_array($proxies) ? array_values($proxies) : [];

        TrustProxies::at(count($proxies) === 1 ? (string) $proxies[0] : $proxies);

        return $next($request);
    }
}
