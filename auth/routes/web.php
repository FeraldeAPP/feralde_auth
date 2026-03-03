<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;

Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'API is running',
        'version' => '1.0.0',
    ]);
});

// CSRF cookie endpoint (for session-based auth)
// This must use 'web' middleware to set the session and CSRF cookies
Route::middleware(['web'])->group(function () {
    Route::get('/csrf-cookie', function () {
        return response()->json(['success' => true, 'message' => 'CSRF cookie set']);
    });

    // Alias for CSRF cookie under /auth prefix
    Route::get('/auth/csrf-cookie', function () {
        return response()->json(['success' => true, 'message' => 'CSRF cookie set']);
    });
});

// Authentication routes without /api prefix (public)
Route::prefix('auth')->middleware(['web', 'force.json'])->group(function () {
    Route::post('/login', [UserController::class, 'login']);
    Route::post('/logout', [UserController::class, 'logout']);
    Route::post('/refresh', [UserController::class, 'refresh']);
    Route::get('/verify-email/{id}', [UserController::class, 'verifyEmail'])->name('verification.verify');
    Route::post('/forgot-password', [UserController::class, 'forgotPassword']);
    Route::post('/reset-password', [UserController::class, 'resetPassword']);
});

// Protected auth-related routes without /api prefix
Route::middleware(['web', 'force.json', 'auth:sanctum'])->group(function () {
    Route::get('/user', [UserController::class, 'me']);
    Route::post('/resend-verification-email', [UserController::class, 'resendVerificationEmail']);
    Route::post('/change-password', [UserController::class, 'changePassword']);
});
