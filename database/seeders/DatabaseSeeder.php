<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Order matters:
     * 1. Truncate all RBAC-related tables
     * 2. Create permissions
     * 3. Create roles
     * 4. Assign permissions to roles
     * 5. Create admin user
     */
    public function run(): void
    {
        $this->truncateRbacTables();
        $this->createPermissions();
        $this->createRoles();
        $this->assignPermissionsToRoles();
        $this->createAdminUser();

        $this->command->info('Database seeding completed successfully!');
    }

    /**
     * Truncate all RBAC-related tables.
     * Users table is NOT truncated — admin is upserted by email.
     */
    private function truncateRbacTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('user_role')->truncate();
        DB::table('role_permission')->truncate();
        DB::table('permissions')->truncate();
        DB::table('roles')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->command->info('Truncated RBAC tables.');
    }

    /**
     * Create permissions — user management only.
     */
    private function createPermissions(): void
    {
        $permissions = [
            [
                'permission' => 'user_management',
                'module' => [
                    'users' => [
                        'users.view',
                        'users.create',
                        'users.update',
                        'users.delete',
                    ],
                    'roles' => [
                        'roles.view',
                        'roles.manage',
                    ],
                ],
            ],
        ];

        foreach ($permissions as $p) {
            DB::table('permissions')->insert([
                'permission'  => $p['permission'],
                'module'      => json_encode($p['module']),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        User::clearModuleToCategoryCache();

        $this->command->info('Created ' . count($permissions) . ' permission category.');
    }

    /**
     * Create roles: admin, manager, user.
     */
    private function createRoles(): void
    {
        $roles = ['admin', 'manager', 'user'];

        foreach ($roles as $name) {
            User::roleQuery()->create([
                'name' => $name,
                'slug' => Str::slug($name),
            ]);
        }

        $this->command->info('Created roles: ' . implode(', ', $roles));
    }

    /**
     * Assign permissions to roles.
     *
     * admin   — all permissions pulled dynamically from the permissions table
     * manager — view users and roles only
     * user    — no permissions (basic authenticated access)
     */
    private function assignPermissionsToRoles(): void
    {
        $adminRole   = User::roleQuery()->where('name', 'admin')->first();
        $managerRole = User::roleQuery()->where('name', 'manager')->first();

        // Admin — dynamically merge all modules from every permission record
        if ($adminRole) {
            $allModules = [];
            foreach (DB::table('permissions')->get() as $record) {
                $modules = json_decode($record->module, true);
                if (is_array($modules)) {
                    $allModules = array_merge($allModules, $modules);
                }
            }

            DB::table('role_permission')->insert([
                'role_id'     => $adminRole->id,
                'permissions' => json_encode($allModules),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            $this->command->info("Assigned all permissions to 'admin'.");
        }

        // Manager — view only
        if ($managerRole) {
            DB::table('role_permission')->insert([
                'role_id'     => $managerRole->id,
                'permissions' => json_encode([
                    'users' => ['users.view'],
                    'roles' => ['roles.view'],
                ]),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            $this->command->info("Assigned view permissions to 'manager'.");
        }
    }

    /**
     * Create or update the admin user and assign the admin role.
     */
    private function createAdminUser(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@socia.com'],
            [
                'name'              => 'Admin',
                'password'          => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $adminRole = User::roleQuery()->where('name', 'admin')->first();

        if ($adminRole) {
            DB::table('user_role')
                ->where('user_id', $admin->id)
                ->delete();

            DB::table('user_role')->insert([
                'user_id'    => $admin->id,
                'role_id'    => $adminRole->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->info("Admin user ready — email: admin@socia.com / password: password");
    }
}
