<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DictionaryController;

Route::prefix('dictionary')->group(function () {
    Route::get('/', [DictionaryController::class, 'index']);
    Route::get('/{id}', [DictionaryController::class, 'show']);
    Route::post('/', [DictionaryController::class, 'store'])->middleware(['auth:sanctum', 'role:superadmin,admin']);
    Route::put('/{id}', [DictionaryController::class, 'update'])->middleware(['auth:sanctum', 'role:superadmin,admin']);
    Route::delete('/{id}', [DictionaryController::class, 'destroy'])->middleware(['auth:sanctum', 'role:superadmin,admin']);
});
