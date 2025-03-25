<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BookmarksController;

Route::middleware(['auth:sanctum'])->prefix('bookmarks')->group(function () {
    Route::get('/', [BookmarksController::class, 'index']);
    Route::post('/', [BookmarksController::class, 'create']);
    Route::delete('/{id}', [BookmarksController::class, 'destroy']);
});

