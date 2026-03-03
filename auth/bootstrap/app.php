<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware) {
        // Register middleware aliases or groups here if needed
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'force.json' => \App\Http\Middleware\ForceJsonResponse::class,
        ]);
        
        // Exclude API routes from CSRF (token-based auth doesn't need CSRF)
        // Also exclude auth routes for cross-domain SPA authentication
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'auth/*',
            'csrf-cookie',
        ]);
        
        // Apply force JSON middleware to all API routes
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);
        
        // Apply force JSON middleware to all web routes as well
        $middleware->web(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions) {

        /*
        |--------------------------------------------------------------------------
        | Force JSON for ALL routes (API-only backend)
        |--------------------------------------------------------------------------
        */
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return true; // Always return JSON for all routes
        });

        /*
        |--------------------------------------------------------------------------
        | Validation Exceptions (422)
        |--------------------------------------------------------------------------
        */
        $exceptions->render(function (ValidationException $e, $request) {
            // Always return JSON for all routes
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        });

        /*
        |--------------------------------------------------------------------------
        | Authentication Exceptions (401)
        |--------------------------------------------------------------------------
        */
        $exceptions->render(function (AuthenticationException $e, $request) {
            // Always return JSON for all routes
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unauthenticated',
            ], 401);
        });

        /*
        |--------------------------------------------------------------------------
        | HTTP Exceptions (401, 403, 404, etc.)
        |--------------------------------------------------------------------------
        */
        $exceptions->render(function (HttpExceptionInterface $e, $request) {
            // Always return JSON for all routes
            $statusCode = $e->getStatusCode();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: match ($statusCode) {
                    401 => 'Unauthorized',
                    403 => 'Forbidden',
                    404 => 'Not found',
                    500 => 'Server error',
                    default => 'An error occurred',
                },
            ], $statusCode);
        });

        /*
        |--------------------------------------------------------------------------
        | Fallback / 500 Errors
        |--------------------------------------------------------------------------
        */
        $exceptions->render(function (Throwable $e, $request) {
            // Always return JSON for all routes - this catches ALL unhandled exceptions
            // Log the actual error for debugging (but don't expose it to client)
            logger()->error('Unhandled exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Server error',
            ], 500);
        });

        /*
        |--------------------------------------------------------------------------
        | Optional: reporting hook (Sentry, logs, etc.)
        |--------------------------------------------------------------------------
        */
        $exceptions->report(function (Throwable $e) {
            if (app()->environment('production')) {
                logger()->error($e);
            }
        });

    })

    ->create();
