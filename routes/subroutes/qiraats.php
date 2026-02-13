<?php

use App\Http\Controllers\QuranController;

Route::prefix('qiraats')->group(function () {
    Route::get('/', [QuranController::class, 'qiraats']);
});
