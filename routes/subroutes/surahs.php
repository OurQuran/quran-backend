<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\QuranController;

Route::prefix('surahs')->group(function () {
    Route::get('/', [QuranController::class, 'surahsIndex']);
    Route::get('/editions', [QuranController::class, 'editions']);
    Route::get('/readings', [QuranController::class, 'readings']);
    Route::get('/{number}', [QuranController::class, 'getBySurah']); // surahs/1?verse=1S
    Route::get('/juz/{number}', [QuranController::class, 'getByJuz']);
    Route::get('/page/{number}', [QuranController::class, 'getByPage']);
    Route::get('/ayah-surah/{ayah}', [QuranController::class, 'getSurahByAyah']); // ayah-surah/1
});
