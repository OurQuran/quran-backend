<?php

use App\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

Route::prefix('tags')->group(function () {
    Route::get('/', [TagController::class, 'index']);
    Route::post('/', [TagController::class, 'store'])->middleware('auth:sanctum');
    Route::get('/{id}', [TagController::class, 'show']);
    Route::put('/{id}', [TagController::class, 'update'])->middleware('auth:sanctum');
    Route::delete('/{id}', [TagController::class, 'destroy'])->middleware('auth:sanctum');

    Route::post('/attach', [TagController::class, 'attachAyahTag'])->middleware('auth:sanctum');
    Route::post('/create-and-attach', [TagController::class, 'createTagAndAttachToAyah'])->middleware('auth:sanctum');
});
