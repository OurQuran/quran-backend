<?php

// Automatically include all route files from subroutes folder
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

foreach (glob(__DIR__ . '/subroutes/*.php') as $routeFile) {
    require $routeFile;
}

Route::post('/login', [UserController::class, 'login']);
Route::post('/logout', [UserController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/me', [UserController::class, 'me'])->middleware('auth:sanctum');
