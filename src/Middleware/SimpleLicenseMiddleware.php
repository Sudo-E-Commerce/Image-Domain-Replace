<?php

namespace Sudo\ImageDomainReplace\Middleware;

use Closure;
use Illuminate\Http\Request;

class SimpleLicenseMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Simple validation - always allow for testing
        return $next($request);
    }
}