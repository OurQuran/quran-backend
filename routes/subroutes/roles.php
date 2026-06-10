<?php

use App\Http\Controllers\RolesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'permission:users.manage,api'])->group(function () {

    // Roles
    Route::get('/roles', [RolesController::class, 'indexRoles']);
    Route::post('/roles', [RolesController::class, 'storeRole']);
    Route::get('/roles/{role}', [RolesController::class, 'showRole']);
    Route::put('/roles/{role}', [RolesController::class, 'updateRole']);
    Route::delete('/roles/{role}', [RolesController::class, 'destroyRole']);
    Route::put('/roles/{role}/permissions', [RolesController::class, 'syncRolePermissions']);

    // Permissions
    Route::get('/permissions', [RolesController::class, 'indexPermissions']);
    Route::post('/permissions', [RolesController::class, 'storePermission']);
    Route::delete('/permissions/{permission}', [RolesController::class, 'destroyPermission']);

    // User ↔ role assignment
    Route::get('/users/{user}/roles', [RolesController::class, 'getUserRoles']);
    Route::post('/users/{user}/roles', [RolesController::class, 'assignRoleToUser']);
    Route::delete('/users/{user}/roles', [RolesController::class, 'removeRoleFromUser']);
    Route::put('/users/{user}/roles', [RolesController::class, 'syncUserRoles']);
});
