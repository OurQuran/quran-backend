<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Serve precompiled qiraat_diff_ayahs and qiraat_diff_words (whole Quran per qiraat).
 * GET .../precompiled/ayahs - paginated ayahs (optional filters)
 * GET .../precompiled/surahs/{surah_number} - paginated ayahs for one surah
 * GET .../precompiled/page/{page_number} - ayahs on one mushaf page
 * GET .../precompiled/juz/{juz_number} - paginated ayahs for one juz
 * GET .../precompiled/ayahs/{id}/words - words (with highlight) for one ayah
 */
class QiraatPrecompiledController extends Controller
{
    /**
     * Paginated list of precompiled ayahs for a qiraat.
     * Query: page, per_page, page_number (mushaf page 1-604), surah_id
     */
    public function ayahs(Request $request, int $qiraat_reading_id)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page_number' => ['nullable', 'integer', 'min:1', 'max:604'],
            'surah_id' => ['nullable', 'integer', 'min:1', 'max:114'],
        ]);

        if (!DB::table('qiraat_readings')->where('id', $qiraat_reading_id)->exists()) {
            return $this->apiError('Qiraat reading not found', 404);
        }

        $query = DB::table('qiraat_diff_ayahs')
            ->where('qiraat_reading_id', $qiraat_reading_id)
            ->orderBy('surah_id')
            ->orderBy('number_in_surah')
            ->orderBy('id');

        if (!empty($validated['page_number'])) {
            $query->where('page', (int) $validated['page_number']);
        }
        if (!empty($validated['surah_id'])) {
            $query->where('surah_id', (int) $validated['surah_id']);
        }

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $totalCount = $query->count();
        $totalPages = (int) max(1, ceil($totalCount / $perPage));
        $items = $query->forPage($page, $perPage)->get();

        return $this->apiSuccess([
            'meta' => [
                'total_count' => $totalCount,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'page_size' => $perPage,
            ],
            'result' => $items,
        ], 'Precompiled ayahs retrieved successfully');
    }

    /**
     * Precompiled ayahs for one surah (paginated).
     * Query: page, per_page
     */
    public function bySurah(Request $request, int $qiraat_reading_id, int $surah_number)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if (!DB::table('qiraat_readings')->where('id', $qiraat_reading_id)->exists()) {
            return $this->apiError('Qiraat reading not found', 404);
        }

        $query = DB::table('qiraat_diff_ayahs')
            ->where('qiraat_reading_id', $qiraat_reading_id)
            ->where('surah_id', $surah_number)
            ->orderBy('number_in_surah')
            ->orderBy('id');

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $totalCount = $query->count();
        $totalPages = (int) max(1, ceil($totalCount / $perPage));
        $items = $query->forPage($page, $perPage)->get();

        return $this->apiSuccess([
            'surah_number' => $surah_number,
            'meta' => [
                'total_count' => $totalCount,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'page_size' => $perPage,
            ],
            'result' => $items,
        ], 'Precompiled ayahs for surah retrieved successfully');
    }

    /**
     * All precompiled ayahs on one mushaf page (no pagination).
     */
    public function pageAyahs(Request $request, int $qiraat_reading_id, int $page_number)
    {
        if (!DB::table('qiraat_readings')->where('id', $qiraat_reading_id)->exists()) {
            return $this->apiError('Qiraat reading not found', 404);
        }

        $ayahs = DB::table('qiraat_diff_ayahs')
            ->where('qiraat_reading_id', $qiraat_reading_id)
            ->where('page', $page_number)
            ->orderBy('number_in_surah')
            ->orderBy('id')
            ->get();

        return $this->apiSuccess([
            'page_number' => $page_number,
            'result' => $ayahs,
        ], 'Precompiled ayahs for page retrieved successfully');
    }

    /**
     * Precompiled ayahs for one juz (paginated).
     * Query: page, per_page
     */
    public function byJuz(Request $request, int $qiraat_reading_id, int $juz_number)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if (!DB::table('qiraat_readings')->where('id', $qiraat_reading_id)->exists()) {
            return $this->apiError('Qiraat reading not found', 404);
        }

        $query = DB::table('qiraat_diff_ayahs')
            ->where('qiraat_reading_id', $qiraat_reading_id)
            ->where('juz_id', $juz_number)
            ->orderBy('surah_id')
            ->orderBy('number_in_surah')
            ->orderBy('id');

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $totalCount = $query->count();
        $totalPages = (int) max(1, ceil($totalCount / $perPage));
        $items = $query->forPage($page, $perPage)->get();

        return $this->apiSuccess([
            'juz_number' => $juz_number,
            'meta' => [
                'total_count' => $totalCount,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'page_size' => $perPage,
            ],
            'result' => $items,
        ], 'Precompiled ayahs for juz retrieved successfully');
    }

    /**
     * Precompiled words (with highlight class) for one precompiled ayah.
     * id = qiraat_diff_ayahs.id
     */
    public function ayahWords(Request $request, int $qiraat_reading_id, int $id)
    {
        $ayah = DB::table('qiraat_diff_ayahs')
            ->where('id', $id)
            ->where('qiraat_reading_id', $qiraat_reading_id)
            ->first();

        if (!$ayah) {
            return $this->apiError('Precompiled ayah not found', 404);
        }

        $words = DB::table('qiraat_diff_words')
            ->where('qiraat_diff_ayah_id', $id)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'mushaf_word_id', 'word_id', 'position', 'word', 'pure_word', 'word_template']);

        return $this->apiSuccess([
            'ayah' => $ayah,
            'result' => $words,
        ], 'Precompiled words for ayah retrieved successfully');
    }
}
