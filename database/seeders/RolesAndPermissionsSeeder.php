<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Defines the roles + permissions used by the admin panel.
 *
 * Roles:
 *   - admin: full access (every permission below)
 *   - user:  default for new signups; carries no admin permissions
 *
 * Permissions:
 *   - view-admin-panel       : enter any /admin/* route
 *   - view-users             : list users
 *   - create-users           : create new users
 *   - edit-users             : edit user profile / role
 *   - delete-users           : delete users
 *   - assign-plans           : assign a plan to a user
 *   - impersonate-users      : log in as another user
 *   - view-global-analytics  : see all-users analytics
 *   - reset-2fa              : disable 2FA on a user
 *   - manage-plans           : CRUD plans
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * All permissions defined by the admin panel.
     *
     * @var array<int, string>
     */
    public const PERMISSIONS = [
        'view-admin-panel',
        'view-users',
        'create-users',
        'edit-users',
        'delete-users',
        'assign-plans',
        'impersonate-users',
        'view-global-analytics',
        'reset-2fa',
        'manage-plans',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Drop any cached permissions / role assignments so changes
        // here take effect immediately.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $adminRole = Role::query()->firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $adminRole->syncPermissions(self::PERMISSIONS);

        // Regular user role exists for symmetry but starts with no
        // permissions — direct ownership checks in the existing
        // policies still govern what they can do.
        Role::query()->firstOrCreate([
            'name' => 'user',
            'guard_name' => 'web',
        ]);
    }
}
