<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    /**
     * Get list of users
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $result = User::getUsers($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Users retrieved successfully',
            'data' => $result,
        ]);
    }

    /**
     * Get a single user
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $result = User::getUserById($id);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'User retrieved successfully',
            'data' => $result['user'],
        ]);
    }

    /**
     * Create a new user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validated = User::validateStore($request->all());
        $result = User::createUser($validated);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'errors' => $result['errors'] ?? [],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $result['user'],
        ], 201);
    }

    /**
     * Update a user
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = User::validateUpdate($request->all(), $id);
        $result = User::updateUserById($id, $validated);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'errors' => $result['errors'] ?? [],
            ], $result['status'] ?? 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $result['user'],
        ]);
    }

    /**
     * Delete a user
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $result = User::deleteUserById($id);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status'] ?? 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * Assign roles to a user
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function assignRoles(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'role_ids' => 'required|array',
            'role_ids.*' => 'exists:roles,id',
        ]);

        $result = User::assignRolesToUser($id, $request->role_ids);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Roles assigned successfully',
            'data' => $result['user'],
        ]);
    }


    /**
     * Get list of all roles
     *
     * @return JsonResponse
     */
    public function roles(): JsonResponse
    {
        $roles = User::roleQuery()->get();

        return response()->json([
            'success' => true,
            'message' => 'Roles retrieved successfully',
            'data' => $roles->map(function ($role) {
                // Get permissions for this role from role_permission table
                $roleId = $role->id;
                $rolePermission = DB::table('role_permission')
                    ->where('role_id', $roleId)
                    ->first();

                $permissions = [];
                if ($rolePermission && $rolePermission->permissions) {
                    $permissionsData = json_decode($rolePermission->permissions, true);
                    if (is_array($permissionsData)) {
                        // Use centralized transformation method from User model (optimized with caching)
                        $permissions = User::transformPermissionsByCategory($permissionsData);
                    }
                }

                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'description' => $role->description,
                    'permissions' => $permissions,
                ];
            }),
        ]);
    }

    /**
     * Get all available permissions from the permissions table.
     *
     * @return JsonResponse
     */
    public function permissions(): JsonResponse
    {
        $permissions = DB::table('permissions')->get()->map(function ($perm) {
            return [
                'id'         => $perm->id,
                'permission' => $perm->permission,
                'module'     => json_decode($perm->module, true) ?? [],
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $permissions,
        ]);
    }

    /**
     * Create a new role with permissions.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function storeRole(Request $request): JsonResponse
    {
        $validated = User::validateRoleStore($request->all());
        $result = User::createRole($validated);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Failed to create role',
                'errors' => $result['errors'] ?? [],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully',
            'data' => $result['role'],
        ], 201);
    }

    /**
     * Register a new user (self-registration)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function register(Request $request): JsonResponse
    {
        $validated = User::validateRegister($request->all());
        $result = User::register($validated);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Registration successful. Please check your email to verify your account.',
            'data' => $result['user'],
        ], 201);
    }

    /**
     * Login user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        $validated = User::validateLogin($request->all());
        $result = User::login($validated, $request);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'errors' => $result['errors'] ?? [],
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => $result['user'],
        ]);
    }

    /**
     * Get current authenticated user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        try {
            // Validate session
            $sessionValidation = User::validateSession($request);
            
            if (!$sessionValidation['valid']) {
                $response = response()->json([
                    'success' => false,
                    'message' => $sessionValidation['message'] ?? 'Session not found in database',
                ], 401);
                
                return $this->expireSessionCookie($response);
            }
            
            $user = $request->user();
            
            // If no user is authenticated, return 401
            if (!$user) {
                // If we have a session but no user, destroy it to prevent reuse
                if ($request->hasSession()) {
                    try {
                        $request->session()->invalidate();
                    } catch (\Exception $e) {
                        // Ignore errors
                    }
                }
                
                $response = response()->json([
                    'success' => false,
                    'message' => 'Not authenticated',
                ], 401);
                
                return $this->expireSessionCookie($response);
            }

            return response()->json([
                'success' => true,
                'data' => $user->toAuthArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error in me() method: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Logout user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user ? $user->id : null;
        
        // Get token ID from request if provided (for token-based auth)
        $tokenId = null;
        if ($request->user() && $request->user()->currentAccessToken()) {
            $tokenId = $request->user()->currentAccessToken()->id;
        }
        
        if ($user) {
            User::logout($user, $tokenId);
        }
        
        // Handle session deletion
        User::handleLogoutSession($request, $userId);

        // Create response and expire session cookie
        $response = response()->json([
            'success' => true,
            'message' => 'Logout successful',
        ]);
        
        // Add headers to prevent caching
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        
        return $this->expireSessionCookie($response);
    }

    /**
     * Refresh session
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function refresh(Request $request): JsonResponse
    {
        // Check if user is authenticated first - return 401 if not
        $user = $request->user();
        
        if (!$user) {
            $response = response()->json([
                'success' => false,
                'message' => 'Not authenticated',
            ], 401);
            
            return $this->expireSessionCookie($response);
        }

        $result = User::refreshSession($request);

        if (!$result['success']) {
            $response = response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 401);
            
            return $this->expireSessionCookie($response);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
        ]);
    }

    /**
     * Verify email address
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function verifyEmail(Request $request, int $id): JsonResponse
    {
        // Verify signed URL
        if (!$request->hasValidSignature()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification link',
            ], 403);
        }

        $hash = $request->input('hash');
        
        if (!$hash) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification link',
            ], 422);
        }

        $result = User::verifyEmail($id, $hash);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
        ]);
    }

    /**
     * Resend email verification
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Not authenticated',
            ], 401);
        }

        $result = User::sendEmailVerification($user);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
        ]);
    }

    /**
     * Request password reset (forgot password)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = User::validateForgotPassword($request->all());
        $result = User::sendPasswordResetEmail($validated['email']);

        // Always return success message for security (don't reveal if email exists)
        return response()->json([
            'success' => true,
            'message' => $result['message'],
        ]);
    }

    /**
     * Reset password with token
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = User::validateResetPassword($request->all());
        $result = User::resetPassword(
            $validated['email'],
            $validated['token'],
            $validated['password']
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
        ]);
    }

    /**
     * Change password for authenticated user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Not authenticated',
            ], 401);
        }

        $validated = User::validateChangePassword($request->all());
        $result = User::changePassword(
            $user,
            $validated['current_password'],
            $validated['password']
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
        ]);
    }

    /**
     * Get CSRF token
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function csrfToken(Request $request): JsonResponse
    {
        // Start session if not already started
        if (!$request->hasSession()) {
            $request->session()->start();
        }
        
        $response = response()->json([
            'success' => true,
            'data' => [
                'csrf_token' => csrf_token(),
            ],
        ]);
        
        // Set CORS headers
        $response->headers->set('Access-Control-Allow-Origin', $request->headers->get('Origin', '*'));
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Expose-Headers', 'X-CSRF-TOKEN');
        
        return $response;
    }

    /**
     * Expire session cookie helper
     *
     * @param JsonResponse $response
     * @return JsonResponse
     */
    private function expireSessionCookie(JsonResponse $response): JsonResponse
    {
        try {
            $cookieName = config('session.cookie', 'laravel_session');
            $path = config('session.path', '/');
            $domain = config('session.domain') ?: null;
            
            // Expire cookie for configured domain
            if ($domain) {
                $cookie = cookie(
                    $cookieName,
                    '',
                    -2628000,
                    $path,
                    $domain,
                    (bool) config('session.secure'),
                    (bool) config('session.http_only', true),
                    false,
                    config('session.same_site', 'lax') ?: 'lax'
                );
                $response = $response->withCookie($cookie);
            }
            
            // Also expire for null domain (localhost)
            $cookieNull = cookie(
                $cookieName,
                '',
                -2628000,
                $path,
                null,
                false,
                (bool) config('session.http_only', true),
                false,
                config('session.same_site', 'lax') ?: 'lax'
            );
            $response = $response->withCookie($cookieNull);
        } catch (\Exception $e) {
            // Continue even if cookie expiration fails
        }
        
        return $response;
    }

    /**
     * Internal service-to-service user creation
     */
    public function internalStore(Request $request): JsonResponse
    {
        $secret = config('services.internal.secret', env('AUTH_SERVICE_SECRET', ''));
        
        if (!empty($secret) && $request->header('X-Service-Secret') !== $secret) {
            return response()->json(['success' => false, 'message' => 'Unauthorized service request'], 401);
        }

        $validated = User::validateStore($request->all());
        $result = User::createUser($validated);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'errors' => $result['errors'] ?? [],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $result['user'],
        ], 201);
    }

    /**
     * Internal service-to-service user deletion (for rollback)
     */
    public function internalDestroy(Request $request, int $id): JsonResponse
    {
        $secret = config('services.internal.secret', env('AUTH_SERVICE_SECRET', ''));
        
        if (!empty($secret) && $request->header('X-Service-Secret') !== $secret) {
            return response()->json(['success' => false, 'message' => 'Unauthorized service request'], 401);
        }

        $result = User::deleteUserById($id);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status'] ?? 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }
}
