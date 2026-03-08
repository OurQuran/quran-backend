<?php

// Automatically include all route files from subroutes folder
use App\Http\Controllers\QuranController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\QiraatAyahController;
use App\Http\Controllers\QiraatWordController;
use App\Http\Controllers\QiraatDifferenceController;
use Illuminate\Support\Facades\Route;

foreach (glob(__DIR__ . '/subroutes/*.php') as $routeFile) {
    require $routeFile;
}

Route::post('/signup', [UserController::class, 'signup']);
Route::post('/login', [UserController::class, 'login']);
Route::post('/logout', [UserController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/me', [UserController::class, 'me'])->middleware('auth:sanctum');
Route::post('/me', [UserController::class, 'deleteAccount'])->middleware('auth:sanctum');

Route::post('/tags-scrape', [TagController::class, 'scrape']);
Route::post('/tags-scrape-array', [TagController::class, 'scrapeArray']);
Route::post('/untag-array', [TagController::class, 'untagArray']);

Route::get('/search', [QuranController::class, 'search']);

// Qiraat: paginated list of qiraat_differences for a reading (table view)
Route::get('/qiraats/{qiraat_reading_id}/differences', [QiraatDifferenceController::class, 'index']);
// Qiraat: ayah-level differences (compare readings for one ayah)
Route::get('/ayahs/{mushaf_ayah_id}/differences', [QiraatAyahController::class, 'differences']);
// Qiraat: word-level variants (differences between words across readings)
Route::get('/words/{mushaf_word_id}/variants', [QiraatWordController::class, 'variants']);
