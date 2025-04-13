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
            Route::post("/{user}/change_password", [UserController::class, 'changeUserPassword']);
            Route::get('/{user}', [UserController::class, 'show']);
            Route::put('/{user}', [UserController::class, 'update']);
            Route::delete('/{user}', [UserController::class, 'destroy']);
        });
    });
});
