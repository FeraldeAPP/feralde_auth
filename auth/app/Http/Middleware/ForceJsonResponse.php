<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force JSON responses for ALL routes (API-only backend)
 * This ensures that even errors return JSON instead of HTML
 */
class ForceJsonResponse
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Force JSON Accept header for ALL routes
        $request->headers->set('Accept', 'application/json');
        
        // Get the response
        $response = $next($request);
        
        // Ensure response is JSON (unless it's already set correctly)
        if (!$response->headers->has('Content-Type') || 
            !str_contains($response->headers->get('Content-Type'), 'application/json')) {
            $response->headers->set('Content-Type', 'application/json');
        }
        
        return $response;
    }
}
