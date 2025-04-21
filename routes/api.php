<?php

// Automatically include all route files from subroutes folder
use App\Http\Controllers\TagController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

foreach (glob(__DIR__ . '/subroutes/*.php') as $routeFile) {
    require $routeFile;
}

Route::post('/signup', [UserController::class, 'signup']);
Route::post('/login', [UserController::class, 'login']);
Route::post('/logout', [UserController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/me', [UserController::class, 'me'])->middleware('auth:sanctum');

Route::post('/tags-scrape', [TagController::class, 'scrape']);
Route::post('/tags-scrape-array', [TagController::class, 'scrapeArray']);
Route::post('/untag', [TagController::class, 'untag']);
Route::post('/untag-array', [TagController::class, 'untagArray']);
