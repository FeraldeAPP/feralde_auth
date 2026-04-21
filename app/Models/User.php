<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session as SessionFacade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'account_type',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_at' => 'datetime',
            'password' => 'hashed',
            'account_type' => 'string',
        ];
    }

    /**
     * Get the social accounts linked to this user.
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function isEmployee(): bool
    {
        return $this->account_type === 'employee';
    }

    public function isCustomer(): bool
    {
        return $this->account_type === 'customer';
    }

    /**
     * Get a model instance for the roles table using Eloquent.
     *
     * @return Model
     */
    protected static function roleModel(): Model
    {
        $model = new class extends Model {
            protected $table = 'roles';
            protected $fillable = ['name', 'slug'];
        };
        
        return $model;
    }

    /**
     * Get a query builder for the roles table using Eloquent.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function roleQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return self::roleModel()->newQuery();
    }

    /**
     * Get a model instance for the permissions table using Eloquent.
     *
     * @return Model
     */
    protected static function permissionModel(): Model
    {
        $model = new class extends Model {
            protected $table = 'permissions';
            protected $fillable = ['permission', 'module'];
            protected $casts = [
                'module' => 'array', // Cast JSON to array
            ];
        };
        
        return $model;
    }

    /**
     * Get a query builder for the permissions table using Eloquent.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function permissionQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return self::permissionModel()->newQuery();
    }

    /**
     * Get the roles for the user.
     */
    public function roles(): BelongsToMany
    {
        // Use the role model class for the relationship
        $roleModelClass = get_class(self::roleModel());
        return $this->belongsToMany($roleModelClass, 'user_role', 'user_id', 'role_id');
    }

    /**
     * Cache for module-to-category mapping (static, shared across all instances)
     * 
     * @var array<string, string>|null
     */
    private static ?array $moduleToCategoryCache = null;

    /**
     * Cache for user permissions (instance-level)
     * 
     * @var array<string, array>|null
     */
    private ?array $permissionsCache = null;

    /**
     * Get all permissions for the user (through roles).
     * Returns merged permissions from all user roles: {"module_name": ["permission1", ...]}
     * Results are cached per user instance to avoid repeated queries.
     */
    public function getPermissionsAttribute()
    {
        // Return cached permissions if available
        if ($this->permissionsCache !== null) {
            return $this->permissionsCache;
        }

        if (!$this->relationLoaded('roles')) {
            $this->load('roles');
        }

        $roleIds = $this->roles->pluck('id')->toArray();

        if (empty($roleIds)) {
            $this->permissionsCache = [];
            return $this->permissionsCache;
        }

        // Get all role permissions and merge them in a single query
        $rolePermissions = DB::table('role_permission')
            ->whereIn('role_id', $roleIds)
            ->get();

        $mergedPermissions = [];

        foreach ($rolePermissions as $rolePermission) {
            $permissions = json_decode($rolePermission->permissions, true);
            if (is_array($permissions)) {
                $mergedPermissions = $this->mergePermissions($mergedPermissions, $permissions);
            }
        }

        // Cache the result
        $this->permissionsCache = $mergedPermissions;
        return $this->permissionsCache;
    }

    /**
     * Clear permissions cache (useful after role changes)
     */
    public function clearPermissionsCache(): void
    {
        $this->permissionsCache = null;
    }

    /**
     * Merge two permission structures, combining arrays and removing duplicates.
     * Structure: {"module_name": ["permission1", "permission2", ...]}
     *
     * @param array $permissions1
     * @param array $permissions2
     * @return array
     */
    private function mergePermissions(array $permissions1, array $permissions2): array
    {
        $merged = $permissions1;

        foreach ($permissions2 as $module => $perms) {
            if (!isset($merged[$module])) {
                $merged[$module] = [];
            }

            // Merge permission arrays and remove duplicates
            $merged[$module] = array_values(array_unique(
                array_merge($merged[$module], $perms)
            ));
        }

        return $merged;
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole(string $roleName): bool
    {
        return $this->roles()->where('name', $roleName)->exists();
    }

    /**
     * Check if user has a specific permission.
     * Searches through structure: {"module_name": ["permission1", ...]}
     * Uses cached permissions for better performance
     */
    public function hasPermission(string $permissionName): bool
    {
        // Super admin has all permissions
        if ($this->hasRole('super-admin')) {
            return true;
        }

        // Use cached permissions instead of querying again
        $userPermissions = $this->permissions;
        return $this->searchPermissionInStructure($userPermissions, $permissionName);
    }

    /**
     * Search for a permission in the structure.
     * Structure: {"module_name": ["permission1", "permission2", ...]}
     *
     * @param array $structure
     * @param string $permissionName
     * @return bool
     */
    private function searchPermissionInStructure(array $structure, string $permissionName): bool
    {
        foreach ($structure as $module => $permissions) {
            if (is_array($permissions) && in_array($permissionName, $permissions)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has any of the given permissions.
     */
    public function hasAnyPermission(array $permissionNames): bool
    {
        // Super admin has all permissions
        if ($this->hasRole('super-admin')) {
            return true;
        }

        $userPermissions = $this->permissions;

        foreach ($permissionNames as $permissionName) {
            if ($this->searchPermissionInStructure($userPermissions, $permissionName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get module-to-category mapping with static caching
     * This mapping rarely changes, so we cache it to avoid repeated database queries
     *
     * @return array<string, string> Module name => Category name
     */
    private static function getModuleToCategoryMapping(): array
    {
        // Return cached mapping if available
        if (self::$moduleToCategoryCache !== null) {
            return self::$moduleToCategoryCache;
        }

        // Get category-to-module mapping from permissions table
        $permissionCategories = DB::table('permissions')->get();
        
        // Build mapping: module_name => category_name
        $moduleToCategory = [];
        foreach ($permissionCategories as $perm) {
            $modules = json_decode($perm->module, true);
            if (is_array($modules)) {
                foreach (array_keys($modules) as $moduleName) {
                    $moduleToCategory[$moduleName] = $perm->permission;
                }
            }
        }

        // Cache the mapping
        self::$moduleToCategoryCache = $moduleToCategory;
        return self::$moduleToCategoryCache;
    }

    /**
     * Clear the module-to-category cache (call after permissions table changes)
     */
    public static function clearModuleToCategoryCache(): void
    {
        self::$moduleToCategoryCache = null;
    }

    /**
     * Transform permissions from module-based to category-based structure
     * Input: {"module_name": ["permission.slug", ...]}
     * Output: {"category_name": {"module_name": ["permission.slug", ...]}}
     * Uses cached module-to-category mapping for better performance
     * 
     * This is a public static method so it can be used by controllers and other classes
     *
     * @param array $modulePermissions
     * @return array
     */
    public static function transformPermissionsByCategory(array $modulePermissions): array
    {
        if (empty($modulePermissions)) {
            return [];
        }

        // Get cached mapping
        $moduleToCategory = self::getModuleToCategoryMapping();

        // Group permissions by category
        $categoryPermissions = [];
        foreach ($modulePermissions as $module => $permissionSlugs) {
            $category = $moduleToCategory[$module] ?? 'other';
            
            if (!isset($categoryPermissions[$category])) {
                $categoryPermissions[$category] = [];
            }
            
            $categoryPermissions[$category][$module] = $permissionSlugs;
        }

        return $categoryPermissions;
    }

    /**
     * Create a new role and attach permissions.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function createRole(array $data): array
    {
        try {
            return DB::transaction(function () use ($data) {
                // Create role record
                $now = now();

                $roleId = DB::table('roles')->insertGetId([
                    'name' => $data['name'],
                    'slug' => $data['slug'],
                    'description' => $data['description'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // Convert flat permission slugs into module-based structure
                // Input: ["files.delete", "files.view", "leads.create"]
                // Output: ["files" => ["files.delete", "files.view"], "leads" => ["leads.create"]]
                $modulePermissions = [];
                foreach ($data['permission_ids'] ?? [] as $permissionSlug) {
                    if (!is_string($permissionSlug) || $permissionSlug === '') {
                        continue;
                    }

                    $parts = explode('.', $permissionSlug);
                    $module = $parts[0] ?? 'other';

                    if (!isset($modulePermissions[$module])) {
                        $modulePermissions[$module] = [];
                    }

                    if (!in_array($permissionSlug, $modulePermissions[$module], true)) {
                        $modulePermissions[$module][] = $permissionSlug;
                    }
                }

                if (!empty($modulePermissions)) {
                    DB::table('role_permission')->insert([
                        'role_id' => $roleId,
                        'permissions' => json_encode($modulePermissions),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $role = DB::table('roles')->where('id', $roleId)->first();

                // Transform permissions for API response (grouped by category, then by module)
                $categoryPermissions = self::transformPermissionsByCategory($modulePermissions);

                return [
                    'success' => true,
                    'role' => [
                        'id' => $role->id,
                        'name' => $role->name,
                        'slug' => $role->slug,
                        'description' => $role->description,
                        'permissions' => $categoryPermissions,
                    ],
                ];
            });
        } catch (\Exception $e) {
            Log::error('Failed to create role', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to create role: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Validate store role request data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws HttpResponseException
     */
    public static function validateRoleStore(array $data): array
    {
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'slug' => ['required', 'string', 'max:255', 'unique:roles,slug'],
            'description' => ['nullable', 'string'],
            // Frontend sends flat list of permission slugs like "files.delete"
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['string'],
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()->toArray(),
                ], 422)
            );
        }

        return $validator->validated();
    }

    /**
     * Get formatted user data with roles and permissions.
     *
     * @return array<string, mixed>
     */
    public function toAuthArray(): array
    {
        $this->load('roles');

        // Get permissions in flat module-based structure
        $modulePermissions = $this->permissions;
        
        // Transform to hierarchical category-based structure
        $categoryPermissions = self::transformPermissionsByCategory($modulePermissions);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'account_type' => $this->account_type,
            'email_verified_at' => $this->email_verified_at,
            'last_login_at' => $this->last_login_at,
            'is_active' => $this->is_active,
            'failed_login_attempts' => $this->failed_login_attempts,
            'locked_at' => $this->locked_at,
            'roles' => $this->roles->map(fn($r) => ['id' => $r->id, 'name' => $r->name])->values()->toArray(),
            'permissions' => $categoryPermissions,
        ];
    }

    /**
     * Validate self-registration request data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws HttpResponseException
     */
    public static function validateRegister(array $data): array
    {
        // Self-registration always creates customer accounts
        $data['account_type'] = 'customer';

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'account_type' => ['required', 'string', 'in:customer'],
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()->toArray(),
                ], 422)
            );
        }

        return $validator->validated();
    }

    /**
     * Register a new user (self-registration).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function register(array $data): array
    {
        try {
            return DB::transaction(function () use ($data) {
                $user = self::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'account_type' => $data['account_type'] ?? 'customer',
                    'password' => Hash::make($data['password']),
                ]);

                self::sendEmailVerification($user);

                return [
                    'success' => true,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                ];
            });
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Validate login request data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws HttpResponseException
     */
    public static function validateLogin(array $data): array
    {
        $validator = Validator::make($data, [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()->toArray(),
                ], 422)
            );
        }

        return $validator->validated();
    }

    /**
     * Validate store user request data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws HttpResponseException
     */
    public static function validateStore(array $data): array
    {
        // Normalize role_ids to integers if provided as strings
        if (isset($data['role_ids']) && is_array($data['role_ids'])) {
            $data['role_ids'] = array_map(static fn ($id) => (int) $id, $data['role_ids']);
        }

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'account_type' => ['required', 'string', 'in:employee,customer,distributor'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['exists:roles,id'],
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()->toArray(),
                ], 422)
            );
        }

        return $validator->validated();
    }

    /**
     * Validate update user request data.
     *
     * @param array<string, mixed> $data
     * @param int|null $userId
     * @return array<string, mixed>
     * @throws HttpResponseException
     */
    public static function validateUpdate(array $data, ?int $userId = null): array
    {
        // Normalize role_ids to integers if provided as strings
        if (isset($data['role_ids']) && is_array($data['role_ids'])) {
            $data['role_ids'] = array_map(static fn ($id) => (int) $id, $data['role_ids']);
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'account_type' => ['sometimes', 'string', 'in:employee,customer,distributor'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['exists:roles,id'],
        ];

        if ($userId) {
            $rules['email'][] = Rule::unique('users')->ignore($userId);
        } else {
            $rules['email'][] = 'unique:users';
        }

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()->toArray(),
                ], 422)
            );
        }

        return $validator->validated();
    }

    /**
     * Login user
     *
     * @param array<string, mixed> $credentials
     * @param Request $request
     * @return array<string, mixed>
     */
    public static function login(array $credentials, Request $request): array
    {
        $user = self::where('email', $credentials['email'])->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => 'Invalid credentials',
            ];
        }

        // Check if user is active
        if ($user->is_active === false) {
            return [
                'success' => false,
                'message' => 'Account is deactivated. Please contact your administrator.',
            ];
        }

        // Check if account is locked
        if ($user->locked_at !== null) {
            return [
                'success' => false,
                'message' => 'Account is locked due to multiple failed login attempts. Please contact your administrator.',
            ];
        }

        if (!Hash::check($credentials['password'], $user->password)) {
            // Increment failed login attempts
            $user->failed_login_attempts = (int) $user->failed_login_attempts + 1;

            // Lock account after 5 failed attempts
            if ($user->failed_login_attempts >= 5) {
                $user->locked_at = now();
            }

            $user->save();

            return [
                'success' => false,
                'message' => 'Invalid credentials',
            ];
        }

        // Reset failed attempts and update last login timestamp on successful login
        $user->failed_login_attempts = 0;
        $user->locked_at = null;
        $user->last_login_at = now();
        $user->save();

        // Create token for API authentication (cross-origin support)
        $token = $user->createToken('auth-token', ['*'])->plainTextToken;

        // Auto-logout any previously authenticated user to enforce single-session
        $currentUser = $request->user();
        if ($currentUser && $currentUser->id !== $user->id) {
            // Log out the previous user
            self::logout($currentUser, null);
            self::handleLogoutSession($request, $currentUser->id);

            Log::info('Auto-logged out previous user to enforce single-session', [
                'previous_user_id' => $currentUser->id,
                'new_user_id' => $user->id,
            ]);
        }

        // Also login for session-based auth (if same origin)
        Auth::login($user);

        // Handle session setup
        self::handleLoginSession($request, $user->id);

        return [
            'success' => true,
            'user' => $user->toAuthArray(),
            'token' => $token,
        ];
    }

    /**
     * Logout user
     *
     * @param User $user
     * @param string|null $tokenId Token ID to revoke (optional, revokes all if null)
     * @return void
     */
    public static function logout(User $user, ?string $tokenId = null): void
    {
        // Revoke all tokens or specific token
        if ($tokenId) {
            $user->tokens()->where('id', $tokenId)->delete();
        } else {
            $user->tokens()->delete();
        }
        
        Auth::logout();
    }

    /**
     * Get a model instance for the sessions table using Eloquent.
     *
     * @return Model
     */
    protected static function sessionModel(): Model
    {
        $sessionTable = config('session.table', 'sessions');
        $connection = config('session.connection');
        
        $model = new class extends Model {
            protected $table;
            protected $primaryKey = 'id';
            public $incrementing = false;
            protected $keyType = 'string';
            public $timestamps = false;
            protected $fillable = [
                'id',
                'user_id',
                'ip_address',
                'user_agent',
                'payload',
                'last_activity',
            ];
        };
        
        $model->setTable($sessionTable);
        if ($connection) {
            $model->setConnection($connection);
        }
        
        return $model;
    }

    /**
     * Get a query builder for the sessions table using Eloquent.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function sessionQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return self::sessionModel()->newQuery();
    }

    /**
     * Handle session setup after login
     *
     * @param Request $request
     * @param int $userId
     * @return void
     */
    public static function handleLoginSession(Request $request, int $userId): void
    {
        // Check if session store is available
        try {
            // Check if request has session store set
            if (!$request->hasSession()) {
                // Try to get session - this will throw if store is not set
                $request->session();
            }
        } catch (\RuntimeException $e) {
            // Session store not set - log and skip session handling
            Log::warning('Session store not available for login', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
            return;
        }

        // Ensure session is started
        if (!$request->hasSession()) {
            $request->session()->start();
        }

        // Regenerate session ID for security (prevents session fixation)
        $request->session()->regenerate();
        
        // Force session to be saved by marking it as dirty
        $request->session()->put('_last_activity', time());
        
        // Get session ID before saving
        $sessionId = $request->session()->getId();
        
        // Explicitly save the session to ensure it's persisted to the database
        SessionFacade::save();
        
        // Directly ensure session is written to database with user_id
        if (config('session.driver') === 'database') {
            try {
                // Wait a moment for the session middleware to write the payload
                usleep(100000); // 100ms delay to ensure middleware has written
                
                // Check if session exists in database (written by middleware)
                $existingSession = self::sessionQuery()->where('id', $sessionId)->first();
                
                if ($existingSession) {
                    // Session exists - update with user_id and metadata
                    self::sessionQuery()->where('id', $sessionId)->update([
                        'user_id' => $userId,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent() ?? '',
                        'last_activity' => time(),
                    ]);
                    Log::info("Updated session in database for user {$userId}", [
                        'session_id' => $sessionId,
                    ]);
                } else {
                    // Session doesn't exist yet - create it with all data
                    $sessionData = $request->session()->all();
                    $payload = base64_encode(serialize($sessionData));
                    
                    self::sessionModel()->create([
                        'id' => $sessionId,
                        'user_id' => $userId,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent() ?? '',
                        'payload' => $payload,
                        'last_activity' => time(),
                    ]);
                    Log::info("Created session in database for user {$userId}", [
                        'session_id' => $sessionId,
                    ]);
                }
                
                // Verify the session was created/updated
                $verifiedSession = self::sessionQuery()
                    ->where('id', $sessionId)
                    ->where('user_id', $userId)
                    ->first();
                if ($verifiedSession) {
                    Log::info("Session verified in database", [
                        'session_id' => $sessionId,
                        'user_id' => $userId,
                        'has_payload' => !empty($verifiedSession->payload),
                    ]);
                } else {
                    Log::warning("Session verification failed - session not found after creation/update", [
                        'session_id' => $sessionId,
                        'user_id' => $userId,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to write session to database: ' . $e->getMessage(), [
                    'exception' => $e,
                    'trace' => $e->getTraceAsString(),
                    'session_id' => $sessionId ?? 'unknown',
                    'user_id' => $userId ?? 'unknown',
                ]);
            }
        }
    }

    /**
     * Handle session setup after registration
     *
     * @param Request $request
     * @param int $userId
     * @return void
     */
    public static function handleRegisterSession(Request $request, int $userId): void
    {
        // Check if session store is available
        try {
            // Check if request has session store set
            if (!$request->hasSession()) {
                // Try to get session - this will throw if store is not set
                $request->session();
            }
        } catch (\RuntimeException $e) {
            // Session store not set - log and skip session handling
            Log::warning('Session store not available for registration', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
            return;
        }

        // Ensure session is started
        if (!$request->hasSession()) {
            $request->session()->start();
        }

        // Regenerate session ID for security
        $request->session()->regenerate();
        
        // Force session to be saved
        $request->session()->put('_last_activity', time());
        
        // Explicitly save the session
        SessionFacade::save();
        
        // Directly ensure session is written to database with user_id
        if (config('session.driver') === 'database') {
            try {
                $sessionId = $request->session()->getId();
                
                // Update or insert session with user_id
                $existing = self::sessionQuery()->where('id', $sessionId)->first();
                if ($existing) {
                    self::sessionQuery()->where('id', $sessionId)->update([
                        'user_id' => $userId,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent() ?? '',
                        'last_activity' => time(),
                    ]);
                } else {
                    self::sessionModel()->create([
                        'id' => $sessionId,
                        'user_id' => $userId,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent() ?? '',
                        'last_activity' => time(),
                        'payload' => '',
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to write session to database: ' . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Validate session exists in database
     *
     * @param Request $request
     * @return array<string, mixed> Returns ['valid' => bool, 'session_id' => string|null]
     */
    public static function validateSession(Request $request): array
    {
        $sessionId = null;
        if ($request->hasSession()) {
            $sessionId = $request->session()->getId();
        }
        
        // Verify session exists in database if using database driver
        if (config('session.driver') === 'database' && $sessionId) {
            $sessionExists = self::sessionQuery()->where('id', $sessionId)->exists();
            
            if (!$sessionExists) {
                // Session cookie exists but no session in DB - invalidate
                if ($request->hasSession()) {
                    try {
                        $request->session()->invalidate();
                    } catch (\Exception $e) {
                        // Ignore errors
                    }
                }
                
                return [
                    'valid' => false,
                    'session_id' => $sessionId,
                    'message' => 'Session not found in database',
                ];
            }
        }
        
        return [
            'valid' => true,
            'session_id' => $sessionId,
        ];
    }

    /**
     * Handle logout session deletion
     *
     * @param Request $request
     * @param int|null $userId
     * @return void
     */
    public static function handleLogoutSession(Request $request, ?int $userId = null): void
    {
        $sessionId = null;
        
        try {
            // Get session ID before any operations
            if ($request->hasSession()) {
                $sessionId = $request->session()->getId();
            }
            
            if ($userId) {
                // Delete all sessions for this user
                if (config('session.driver') === 'database') {
                    try {
                        $deleted = self::sessionQuery()->where('user_id', $userId)->delete();
                        Log::info("Deleted {$deleted} session(s) for user {$userId} during logout");
                        
                        // Also delete by specific session ID if provided (extra safety)
                        if ($sessionId) {
                            self::sessionQuery()->where('id', $sessionId)->delete();
                        }
                    } catch (\Exception $e) {
                        Log::error('Failed to delete sessions during logout: ' . $e->getMessage(), [
                            'user_id' => $userId,
                            'session_id' => $sessionId,
                            'exception' => $e,
                        ]);
                    }
                }
            } else {
                // No authenticated user, but still try to delete session if session ID exists
                if ($sessionId && config('session.driver') === 'database') {
                    try {
                        self::sessionQuery()->where('id', $sessionId)->delete();
                        Log::info("Deleted session {$sessionId} during logout (no authenticated user)");
                    } catch (\Exception $e) {
                        // Ignore errors for unauthenticated logout
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Logout error: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
        }

        // Always invalidate session if it exists
        if ($request->hasSession()) {
            try {
                $request->session()->invalidate();
            } catch (\Exception $e) {
                // Ignore errors
            }
        }
    }

    /**
     * Refresh session
     *
     * @param Request $request
     * @return array<string, mixed> Returns ['success' => bool, 'message' => string]
     */
    public static function refreshSession(Request $request): array
    {
        // Check if session store is available
        try {
            // Check if request has session store set
            if (!$request->hasSession()) {
                // Try to get session - this will throw if store is not set
                $request->session();
            }
        } catch (\RuntimeException $e) {
            // Session store not set - return error
            return [
                'success' => false,
                'message' => 'Session store not available',
            ];
        }

        // Ensure session is started
        if (!$request->hasSession()) {
            $request->session()->start();
        }

        // Verify session exists in database (for database driver)
        if (config('session.driver') === 'database') {
            $sessionId = null;
            if ($request->hasSession()) {
                $sessionId = $request->session()->getId();
            }
            
            // Check if session exists in database
            if ($sessionId) {
                $session = self::sessionQuery()->where('id', $sessionId)->first();
                
                if (!$session) {
                    // Session cookie exists but no session in DB - invalidate
                    if ($request->hasSession()) {
                        try {
                            $request->session()->invalidate();
                        } catch (\Exception $e) {
                            // Ignore errors
                        }
                    }
                    
                    return [
                        'success' => false,
                        'message' => 'Session not found in database',
                    ];
                }
                
                // Update session activity
                self::sessionQuery()->where('id', $sessionId)->update([
                    'last_activity' => time(),
                ]);
            }
        }

        // Regenerate session ID for security (prevents session fixation)
        // Only regenerate if we have a valid session
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return [
            'success' => true,
            'message' => 'Session refreshed',
        ];
    }

    /**
     * Get list of users with pagination
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function getUsers(array $filters = []): array
    {
        $query = self::with('roles');

        // Apply filters
        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = $filters['per_page'] ?? 15;
        $users = $query->paginate($perPage);

        // Get all role IDs from all users to optimize queries
        $allRoleIds = collect($users->items())
            ->flatMap(fn($user) => $user->roles->pluck('id'))
            ->unique()
            ->values()
            ->toArray();

        // Fetch all role permissions in a single query (optimize N+1 problem)
        $allRolePermissions = [];
        if (!empty($allRoleIds)) {
            $rolePermissionsData = DB::table('role_permission')
                ->whereIn('role_id', $allRoleIds)
                ->get()
                ->keyBy('role_id');

            foreach ($rolePermissionsData as $rolePermission) {
                $permissionsData = json_decode($rolePermission->permissions, true);
                if (is_array($permissionsData)) {
                    $allRolePermissions[$rolePermission->role_id] = $permissionsData;
                }
            }
        }

        // Get cached module-to-category mapping (optimized)
        $moduleToCategory = self::getModuleToCategoryMapping();

        // Transform users to include permissions grouped by category, then by module
        $transformedUsers = collect($users->items())->map(function ($user) use ($allRolePermissions, $moduleToCategory) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'account_type' => $user->account_type,
                'email_verified_at' => $user->email_verified_at,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'roles' => $user->roles->map(function ($role) use ($allRolePermissions, $moduleToCategory) {
                    // Get permissions from pre-loaded data (no additional query)
                    $permissionsData = $allRolePermissions[$role->id] ?? [];
                    
                    // Transform permissions grouped by category, then by module
                    $categoryPermissions = [];
                    foreach ($permissionsData as $module => $permissionSlugs) {
                        $category = $moduleToCategory[$module] ?? 'other';
                        
                        if (!isset($categoryPermissions[$category])) {
                            $categoryPermissions[$category] = [];
                        }
                        
                        $categoryPermissions[$category][$module] = $permissionSlugs;
                    }

                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'permissions' => $categoryPermissions,
                    ];
                })->toArray(),
            ];
        })->toArray();

        return [
            'users' => $transformedUsers,
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ];
    }

    /**
     * Get a single user with roles and permissions
     *
     * @param int $id
     * @return array<string, mixed>
     */
    public static function getUserById(int $id): array
    {
        $user = self::with('roles')->find($id);

        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found',
            ];
        }

        // Get all permissions through roles (flat module-based structure)
        $modulePermissions = $user->permissions;
        
        // Transform to hierarchical category-based structure
        $categoryPermissions = self::transformPermissionsByCategory($modulePermissions);

        return [
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'account_type' => $user->account_type,
                'email_verified_at' => $user->email_verified_at,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'roles' => $user->roles->pluck('name')->toArray(),
                'permissions' => $categoryPermissions,
            ],
        ];
    }

    /**
     * Create a new user
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function createUser(array $data): array
    {
        try {
            return DB::transaction(function () use ($data) {
                $user = self::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'account_type' => $data['account_type'] ?? 'employee',
                    'password' => Hash::make($data['password']),
                ]);

                // Assign roles if provided
                if (isset($data['role_ids']) && is_array($data['role_ids'])) {
                    $user->roles()->sync($data['role_ids']);
                    // Clear permissions cache after role change
                    $user->clearPermissionsCache();
                }

                $user->load('roles');
                $modulePermissions = $user->permissions;
                $categoryPermissions = self::transformPermissionsByCategory($modulePermissions);

                return [
                    'success' => true,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'account_type' => $user->account_type,
                        'roles' => $user->roles->pluck('name')->toArray(),
                        'permissions' => $categoryPermissions,
                    ],
                ];
            });
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to create user: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Update a user
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function updateUserById(int $id, array $data): array
    {
        $user = self::find($id);

        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found',
                'status' => 404,
            ];
        }

        try {
            return $user->getConnection()->transaction(function () use ($user, $data) {
                $updateData = [
                    'name' => $data['name'],
                    'email' => $data['email'],
                ];

                if (isset($data['account_type'])) {
                    $updateData['account_type'] = $data['account_type'];
                }

                if (isset($data['password']) && !empty($data['password'])) {
                    $updateData['password'] = Hash::make($data['password']);
                }

                $user->update($updateData);

                // Update roles if provided
                if (isset($data['role_ids']) && is_array($data['role_ids'])) {
                    $user->roles()->sync($data['role_ids']);
                    // Clear permissions cache after role change
                    $user->clearPermissionsCache();
                }

                $user->load('roles');
                $modulePermissions = $user->permissions;
                $categoryPermissions = self::transformPermissionsByCategory($modulePermissions);

                return [
                    'success' => true,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'account_type' => $user->account_type,
                        'roles' => $user->roles->pluck('name')->toArray(),
                        'permissions' => $categoryPermissions,
                    ],
                ];
            });
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update user: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Delete a user
     *
     * @param int $id
     * @return array<string, mixed>
     */
    public static function deleteUserById(int $id): array
    {
        $user = self::find($id);

        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found',
                'status' => 404,
            ];
        }

        // Prevent deleting super-admin
        if ($user->hasRole('super-admin')) {
            return [
                'success' => false,
                'message' => 'Cannot delete super-admin user',
                'status' => 403,
            ];
        }

        $user->delete();

        return [
            'success' => true,
        ];
    }

    /**
     * Assign roles to a user
     *
     * @param int $userId
     * @param array<int> $roleIds
     * @return array<string, mixed>
     */
    public static function assignRolesToUser(int $userId, array $roleIds): array
    {
        $user = self::find($userId);

        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found',
            ];
        }

        $user->roles()->sync($roleIds);
        // Clear permissions cache after role change
        $user->clearPermissionsCache();
        $user->load('roles');
        $modulePermissions = $user->permissions;
        $categoryPermissions = self::transformPermissionsByCategory($modulePermissions);

        return [
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')->toArray(),
                'permissions' => $categoryPermissions,
            ],
        ];
    }

    /**
     * Clean up orphaned records in role_permission table.
     * Removes entries that reference non-existent roles.
     *
     * @return int Number of orphaned records removed
     */
    public static function cleanupOrphanedRolePermissions(): int
    {
        $orphanedCount = DB::table('role_permission')
            ->leftJoin('roles', 'role_permission.role_id', '=', 'roles.id')
            ->whereNull('roles.id')
            ->count();

        if ($orphanedCount > 0) {
            DB::table('role_permission')
                ->leftJoin('roles', 'role_permission.role_id', '=', 'roles.id')
                ->whereNull('roles.id')
                ->delete();
        }

        return $orphanedCount;
    }

    /**
     * Get a model instance for the password reset tokens table using Eloquent.
     *
     * @return Model
     */
    protected static function passwordResetTokenModel(): Model
    {
        $model = new class extends Model {
            protected $table = 'password_reset_tokens';
            protected $primaryKey = 'email';
            protected $keyType = 'string';
            public $incrementing = false;
            public $timestamps = false;
            protected $fillable = ['email', 'token', 'created_at'];
        };
        
        return $model;
    }

    /**
     * Get a query builder for the password reset tokens table using Eloquent.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function passwordResetTokenQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return self::passwordResetTokenModel()->newQuery();
    }

    /**
     * Validate email verification request data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws HttpResponseException
     */
    public static function validateEmailVerification(array $data): array
    {
        $validator = Validator::make($data, [
            'token' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()->toArray(),
                ], 422)
            );
        }

        return $validator->validated();
    }

    /**
     * Validate forgot password request data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws HttpResponseException
     */
    public static function validateForgotPassword(array $data): array
    {
        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()->toArray(),
                ], 422)
            );
        }

        return $validator->validated();
    }

    /**
     * Validate reset password request data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws HttpResponseException
     */
    public static function validateResetPassword(array $data): array
    {
        $validator = Validator::make($data, [
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()->toArray(),
                ], 422)
            );
        }

        return $validator->validated();
    }

    /**
     * Validate change password request data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws HttpResponseException
     */
    public static function validateChangePassword(array $data): array
    {
        $validator = Validator::make($data, [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()->toArray(),
                ], 422)
            );
        }

        return $validator->validated();
    }

    /**
     * Send email verification notification.
     *
     * @param User $user
     * @return array<string, mixed>
     */
    public static function sendEmailVerification(User $user): array
    {
        if ($user->email_verified_at) {
            return [
                'success' => false,
                'message' => 'Email already verified',
            ];
        }

        try {
            // Create a signed URL that expires in 24 hours
            $verificationUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addHours(24),
                ['id' => $user->id, 'hash' => sha1($user->email)]
            );

            // Send email using Mail facade
            Mail::raw("Please verify your email by clicking this link: {$verificationUrl}", function ($message) use ($user) {
                $message->to($user->email)
                        ->subject('Verify Your Email Address');
            });

            Log::info("Email verification sent to user {$user->id}");

            return [
                'success' => true,
                'message' => 'Verification email sent successfully',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to send verification email: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to send verification email',
            ];
        }
    }

    /**
     * Verify user email.
     *
     * @param int $userId
     * @param string $hash
     * @return array<string, mixed>
     */
    public static function verifyEmail(int $userId, string $hash): array
    {
        $user = self::find($userId);

        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found',
            ];
        }

        if ($user->email_verified_at) {
            return [
                'success' => false,
                'message' => 'Email already verified',
            ];
        }

        // Verify hash matches email
        if (sha1($user->email) !== $hash) {
            return [
                'success' => false,
                'message' => 'Invalid verification link',
            ];
        }

        $user->email_verified_at = now();
        $user->save();

        Log::info("Email verified for user {$user->id}");

        return [
            'success' => true,
            'message' => 'Email verified successfully',
        ];
    }

    /**
     * Send password reset notification.
     *
     * @param string $email
     * @return array<string, mixed>
     */
    public static function sendPasswordResetEmail(string $email): array
    {
        $user = self::where('email', $email)->first();

        if (!$user) {
            // Don't reveal if email exists for security
            return [
                'success' => true,
                'message' => 'If the email exists, a password reset link has been sent',
            ];
        }

        try {
            $token = Str::random(64);
            $resetUrl = env('FRONTEND_URL', 'http://localhost:3000') . '/reset-password?token=' . $token . '&email=' . urlencode($email);

            // Store reset token in password_reset_tokens table
            self::passwordResetTokenModel()->updateOrCreate(
                ['email' => $email],
                [
                    'token' => Hash::make($token),
                    'created_at' => now(),
                ]
            );

            // Send email
            Mail::raw("Click this link to reset your password: {$resetUrl}", function ($message) use ($user) {
                $message->to($user->email)
                        ->subject('Reset Your Password');
            });

            Log::info("Password reset email sent to user {$user->id}");

            return [
                'success' => true,
                'message' => 'If the email exists, a password reset link has been sent',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to send password reset email: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to send password reset email',
            ];
        }
    }

    /**
     * Reset user password.
     *
     * @param string $email
     * @param string $token
     * @param string $password
     * @return array<string, mixed>
     */
    public static function resetPassword(string $email, string $token, string $password): array
    {
        $user = self::where('email', $email)->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => 'Invalid reset token',
            ];
        }

        // Get reset token record
        $resetRecord = self::passwordResetTokenQuery()->where('email', $email)->first();

        if (!$resetRecord) {
            return [
                'success' => false,
                'message' => 'Invalid or expired reset token',
            ];
        }

        // Check if token is valid (within 60 minutes)
        $tokenAge = now()->diffInMinutes($resetRecord->created_at);
        if ($tokenAge > 60) {
            $resetRecord->delete();
            return [
                'success' => false,
                'message' => 'Reset token has expired',
            ];
        }

        // Verify token
        if (!Hash::check($token, $resetRecord->token)) {
            return [
                'success' => false,
                'message' => 'Invalid reset token',
            ];
        }

        // Update password
        $user->password = Hash::make($password);
        $user->save();

        // Delete reset token
        $resetRecord->delete();

        Log::info("Password reset successful for user {$user->id}");

        return [
            'success' => true,
            'message' => 'Password reset successfully',
        ];
    }

    /**
     * Change user password.
     *
     * @param User $user
     * @param string $currentPassword
     * @param string $newPassword
     * @return array<string, mixed>
     */
    public static function changePassword(User $user, string $currentPassword, string $newPassword): array
    {
        // Verify current password
        if (!Hash::check($currentPassword, $user->password)) {
            return [
                'success' => false,
                'message' => 'Current password is incorrect',
            ];
        }

        // Update password
        $user->password = Hash::make($newPassword);
        $user->save();

        Log::info("Password changed for user {$user->id}");

        return [
            'success' => true,
            'message' => 'Password changed successfully',
        ];
    }
}
