<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

Route::prefix('users')->group(function () {
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::delete('/', [UserController::class, 'deleteAccount']);
        Route::post("/change_password", [UserController::class, 'changeOwnPassword']);

        Route::middleware(['role:superadmin'])->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::post("/{id}/change_password", [UserController::class, 'changeUserPassword']);
            Route::get('/{id}', [UserController::class, 'show']);
            Route::put('/{id}', [UserController::class, 'update']);
            Route::delete('/{id}', [UserController::class, 'destroy']);
        });
    });
});
