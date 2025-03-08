<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SurahController;

Route::prefix('surahs')->group(function () {
    Route::get('/', [SurahController::class, 'index']);
    Route::get('/editions', [SurahController::class, 'editions']);
    Route::get('/{number}', [SurahController::class, 'getBySurah']); // surahs/1?verse=1S
    Route::get('/juz/{number}', [SurahController::class, 'getByJuz']);
    Route::get('/page/{number}', [SurahController::class, 'getByPage']);
    Route::get('/ayah-surah/{ayah}', [SurahController::class, 'getSurahByAyah']); // ayah-surah/1
});

