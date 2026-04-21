<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\UserController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All API routes return JSON only (no HTML, no views)
|
*/


// CSRF token endpoint (no auth required, works with CORS)
// Route::get('/csrf-token', [UserController::class, 'csrfToken'])->middleware(['web', 'force.json']);

// Authentication routes (support both session and token-based auth)
Route::prefix('auth')->middleware(['web', 'force.json'])->group(function () {
    Route::post('/register', [UserController::class, 'register']);
    Route::post('/login', [UserController::class, 'login']);
    // Logout doesn't require auth - it should work even if session is invalid
    // This allows cleanup of stale cookies
    Route::post('/logout', [UserController::class, 'logout']);
    // Refresh doesn't require auth - it works with session cookie
    Route::post('/refresh', [UserController::class, 'refresh']);
    // Email verification routes
    Route::get('/verify-email/{id}', [UserController::class, 'verifyEmail'])->name('verification.verify');
    Route::post('/forgot-password', [UserController::class, 'forgotPassword']);
    Route::post('/reset-password', [UserController::class, 'resetPassword']);
});

// Social OAuth routes
// - redirect: returns JSON with the provider URL (needs force.json)
// - callback: redirects the browser back to the frontend (must NOT use force.json)
Route::prefix('auth')->middleware('web')->group(function () {
    Route::get('/social/{provider}/redirect',  [SocialAuthController::class, 'redirect'])->middleware('force.json');
    Route::get('/social/{provider}/callback',  [SocialAuthController::class, 'callback']);
});

// -------------------------------------------------------------------------
// Internal service-to-service routes (no session/auth required, protected
// by a shared secret validated in the middleware/controller)
// -------------------------------------------------------------------------
Route::prefix('internal')->middleware(['force.json', 'throttle:60,1'])->group(function () {
    Route::post('/users',       [UserController::class, 'internalStore']);
    Route::delete('/users/{id}', [UserController::class, 'internalDestroy']);
});

// Protected routes (require authentication and session middleware for cookie-based auth)
Route::middleware(['web', 'force.json', 'auth:sanctum'])->group(function () {
    // Current authenticated user
    Route::get('/user', [UserController::class, 'me']);
    
    // Email verification and password management
    Route::post('/resend-verification-email', [UserController::class, 'resendVerificationEmail']);
    Route::post('/change-password', [UserController::class, 'changePassword']);

    // Roles and permissions endpoints
    Route::get('/permissions', [UserController::class, 'permissions'])->middleware('permission:roles.view');
    Route::get('/roles', [UserController::class, 'roles'])->middleware('permission:users.view');
    Route::post('/roles', [UserController::class, 'storeRole'])->middleware('permission:users.create');

    // User management routes with permission checks
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('/', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::get('/{id}', [UserController::class, 'show'])->middleware('permission:users.view');
        Route::put('/{id}', [UserController::class, 'update'])->middleware('permission:users.update');
        Route::delete('/{id}', [UserController::class, 'destroy'])->middleware('permission:users.delete');
        Route::post('/{id}/roles', [UserController::class, 'assignRoles'])->middleware('permission:users.update');
    });
});

