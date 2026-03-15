<?php

use App\Http\Controllers\BookController;
use Illuminate\Support\Facades\Route;

Route::prefix('books')->group(function () {
    Route::get('/', [BookController::class, 'index']);
    Route::get('/{id}', [BookController::class, 'show']);
    Route::get('/{id}/sections', [BookController::class, 'sections']);
    Route::get('/{id}/sections/{order_no}', [BookController::class, 'showSection']);
});
