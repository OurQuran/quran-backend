<?php

use App\Http\Controllers\QiraatDifferenceController;
use App\Http\Controllers\QiraatPrecompiledController;
use App\Http\Controllers\QuranController;
use Illuminate\Support\Facades\Route;

// Qiraat: paginated list of qiraat_differences for a reading (table view)
Route::prefix('qiraats')->group(function () {
    Route::get('/', [QuranController::class, 'qiraats']);
    Route::get('/{qiraat_reading_id}/differences', [QiraatDifferenceController::class, 'index']);
    // Qiraat: precompiled whole-Quran (qiraat_diff_ayahs / qiraat_diff_words)
    Route::get('/{qiraat_reading_id}/precompiled/ayahs', [QiraatPrecompiledController::class, 'ayahs']);
    Route::get('/{qiraat_reading_id}/precompiled/surahs/{surah_number}', [QiraatPrecompiledController::class, 'bySurah']);
    Route::get('/{qiraat_reading_id}/precompiled/page/{page_number}', [QiraatPrecompiledController::class, 'pageAyahs']);
    Route::get('/{qiraat_reading_id}/precompiled/juz/{juz_number}', [QiraatPrecompiledController::class, 'byJuz']);
    Route::get('/{qiraat_reading_id}/precompiled/ayahs/{qiraat_diff_ayah_id}/words', [QiraatPrecompiledController::class, 'ayahWords']);
});
