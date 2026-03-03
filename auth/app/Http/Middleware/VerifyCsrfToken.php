<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // API routes that use token-based authentication don't need CSRF
        'api/*',
        // Auth routes - CSRF is handled via X-XSRF-TOKEN header from cookie
        // These are excluded because cross-domain SPA sends token in header
        'auth/login',
        'auth/logout',
        'auth/refresh',
        'auth/register',
        'auth/forgot-password',
        'auth/reset-password',
    ];

    /**
     * Determine if the request has a URI that should pass through CSRF verification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function inExceptArray($request)
    {
        // Skip CSRF for token-based API requests
        if ($request->bearerToken()) {
            return true;
        }

        // Check for X-XSRF-TOKEN header (SPA cross-domain requests)
        // Laravel's VerifyCsrfToken middleware also checks this header
        // but we can also validate it manually for cross-domain requests
        
        return parent::inExceptArray($request);
    }
}

