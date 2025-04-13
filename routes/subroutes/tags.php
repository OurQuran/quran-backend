<?php

use App\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

Route::prefix('tags')->group(function () {
    Route::get('/', [TagController::class, 'index']);
    Route::post('/', [TagController::class, 'store'])->middleware('auth:sanctum');
    Route::get('/unapproved', [TagController::class, 'getUnapprovedTags'])->middleware('auth:sanctum');
    Route::get('/tags_search', [TagController::class, 'searchTags']);
    Route::get('/{tag}', [TagController::class, 'show']);
    Route::put('/{tag}', [TagController::class, 'update'])->middleware('auth:sanctum');
    Route::delete('/{tag}', [TagController::class, 'destroy'])->middleware('auth:sanctum');

    Route::post('/approve', [TagController::class, 'approve'])->middleware( 'auth:sanctum');
    Route::post('/unapprove', [TagController::class, 'unapprove'])->middleware( 'auth:sanctum');
    Route::post('/attach', [TagController::class, 'attachAyahTag'])->middleware('auth:sanctum');
    Route::post('/create-and-attach', [TagController::class, 'createTagAndAttachToAyah'])->middleware('auth:sanctum');
});
