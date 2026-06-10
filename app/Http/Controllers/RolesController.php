<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesController extends Controller
{
    // -------------------------------------------------------------------------
    // Roles
    // -------------------------------------------------------------------------

    public function indexRoles(): JsonResponse
    {
        $roles = Role::where('guard_name', 'api')
            ->withCount('permissions')
            ->get(['id', 'name', 'guard_name']);

        return $this->apiSuccess($roles, 'Roles retrieved successfully');
    }

    public function showRole(Role $role): JsonResponse
    {
        $role->load('permissions:id,name');

        return $this->apiSuccess($role, 'Role retrieved successfully');
    }

    public function storeRole(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:roles,name',
        ]);

        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'api']);

        return $this->apiSuccess($role, 'Role created successfully', 201);
    }

    public function updateRole(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:roles,name,' . $role->id,
        ]);

        $role->update(['name' => $validated['name']]);

        return $this->apiSuccess($role, 'Role updated successfully');
    }

    public function destroyRole(Role $role): JsonResponse
    {
        if (in_array($role->name, ['user', 'admin', 'superadmin'])) {
            return $this->apiError('Built-in roles cannot be deleted', 422);
        }

        $role->delete();

        return $this->apiSuccess(null, 'Role deleted successfully');
    }

    // -------------------------------------------------------------------------
    // Permissions on a role
    // -------------------------------------------------------------------------

    public function syncRolePermissions(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role->syncPermissions($validated['permissions']);

        return $this->apiSuccess($role->load('permissions:id,name'), 'Role permissions updated successfully');
    }

    // -------------------------------------------------------------------------
    // Permissions
    // -------------------------------------------------------------------------

    public function indexPermissions(): JsonResponse
    {
        $permissions = Permission::where('guard_name', 'api')
            ->orderBy('name')
            ->get(['id', 'name']);

        return $this->apiSuccess($permissions, 'Permissions retrieved successfully');
    }

    public function storePermission(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:permissions,name',
        ]);

        $permission = Permission::create(['name' => $validated['name'], 'guard_name' => 'api']);

        return $this->apiSuccess($permission, 'Permission created successfully', 201);
    }

    public function destroyPermission(Permission $permission): JsonResponse
    {
        $permission->delete();

        return $this->apiSuccess(null, 'Permission deleted successfully');
    }

    // -------------------------------------------------------------------------
    // User ↔ role assignment
    // -------------------------------------------------------------------------

    public function getUserRoles(User $user): JsonResponse
    {
        return $this->apiSuccess([
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ], 'User roles retrieved successfully');
    }

    public function assignRoleToUser(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role' => 'required|string|exists:roles,name',
        ]);

        if ($user->hasRole($validated['role'])) {
            return $this->apiError("User already has the '{$validated['role']}' role", 422);
        }

        $user->assignRole($validated['role']);

        return $this->apiSuccess(null, "Role '{$validated['role']}' assigned successfully");
    }

    public function removeRoleFromUser(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role' => 'required|string|exists:roles,name',
        ]);

        if ($validated['role'] === 'superadmin') {
            return $this->apiError("The 'superadmin' role cannot be removed", 422);
        }

        if (!$user->hasRole($validated['role'])) {
            return $this->apiError("User does not have the '{$validated['role']}' role", 422);
        }

        $user->removeRole($validated['role']);

        return $this->apiSuccess(null, "Role '{$validated['role']}' removed successfully");
    }

    public function syncUserRoles(Request $request, User $user): JsonResponse
    {
        if ($user->hasRole('superadmin')) {
            return $this->apiError("Roles of a superadmin cannot be changed", 422);
        }

        $validated = $request->validate([
            'roles' => 'required|array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        if (in_array('superadmin', $validated['roles'])) {
            return $this->apiError("Cannot assign the 'superadmin' role", 422);
        }

        $user->syncRoles($validated['roles']);

        return $this->apiSuccess(null, 'User roles synced successfully');
    }
}
