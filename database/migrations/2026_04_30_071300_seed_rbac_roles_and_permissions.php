<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const GUARD = 'api';

    private const ROLES = [
        'user',
        'admin',
        'superadmin',
    ];

    private const PERMISSIONS = [
        'users.manage',
        'tags.moderate',
        'dictionary.manage',
    ];

    private const ROLE_PERMISSIONS = [
        'admin' => [
            'tags.moderate',
            'dictionary.manage',
        ],
        'superadmin' => [
            'users.manage',
            'tags.moderate',
            'dictionary.manage',
        ],
    ];

    public function up(): void
    {
        foreach (self::ROLES as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role, 'guard_name' => self::GUARD],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => self::GUARD],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $roleId = DB::table('roles')
                ->where('name', $roleName)
                ->where('guard_name', self::GUARD)
                ->value('id');

            foreach ($permissions as $permissionName) {
                $permissionId = DB::table('permissions')
                    ->where('name', $permissionName)
                    ->where('guard_name', self::GUARD)
                    ->value('id');

                DB::table('role_has_permissions')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        $roleIds = DB::table('roles')
            ->where('guard_name', self::GUARD)
            ->pluck('id', 'name');

        User::query()
            ->whereIn('role', self::ROLES)
            ->each(function (User $user) use ($roleIds) {
                DB::table('model_has_roles')->updateOrInsert([
                    'role_id' => $roleIds[$user->role],
                    'model_type' => User::class,
                    'model_id' => $user->id,
                ]);
            });

        app('cache')->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('role_id', function ($query) {
                $query->select('id')
                    ->from('roles')
                    ->whereIn('name', array_keys(self::ROLE_PERMISSIONS))
                    ->where('guard_name', self::GUARD);
            })
            ->delete();

        DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->whereIn('role_id', function ($query) {
                $query->select('id')
                    ->from('roles')
                    ->whereIn('name', self::ROLES)
                    ->where('guard_name', self::GUARD);
            })
            ->delete();

        DB::table('permissions')
            ->whereIn('name', self::PERMISSIONS)
            ->where('guard_name', self::GUARD)
            ->delete();

        DB::table('roles')
            ->whereIn('name', self::ROLES)
            ->where('guard_name', self::GUARD)
            ->delete();

        app('cache')->forget(config('permission.cache.key'));
    }
};
