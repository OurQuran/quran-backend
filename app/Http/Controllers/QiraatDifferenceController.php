<?php

namespace App\Http\Controllers;

use App\Support\QiraatImportMaps;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Paginated list of qiraat_differences for a given qiraat reading.
 * GET /qiraats/{qiraat_reading_id}/differences?page=1&per_page=15&surah=1&ayah=2
 */
class QiraatDifferenceController extends Controller
{
    /**
     * Lightweight difference counts for every Surah in one request.
     * GET /qiraats/{qiraat_reading_id}/difference-counts
     */
    public function counts(string $qiraat_reading_id)
    {
        $resolvedId = QiraatImportMaps::resolveReadingId($qiraat_reading_id);
        if (!$resolvedId) {
            return response()->json(['message' => 'Qiraat reading not found'], 404);
        }

        $cacheKey = "qiraat_difference_counts:v1:{$resolvedId}";

        $data = Cache::remember($cacheKey, now()->addDay(), function () use ($resolvedId) {
            $countsBySurah = DB::table('qiraat_differences')
                ->select('surah', DB::raw('COUNT(*) as difference_count'))
                ->where('qiraat_reading_id', $resolvedId)
                ->groupBy('surah')
                ->pluck('difference_count', 'surah');

            return collect(range(1, 114))
                ->map(fn(int $surah) => [
                    'surah_id' => $surah,
                    'difference_count' => (int) ($countsBySurah[$surah] ?? 0),
                ])
                ->all();
        });

        return $this->apiSuccess(['data' => $data], 'Qiraat difference counts retrieved successfully');
    }

    /**
     * List qiraat_differences for a qiraat reading (paginated).
     * Optional filters: surah, ayah.
     */
    public function index(Request $request, string $qiraat_reading_id)
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'surah' => ['nullable', 'integer', 'min:1', 'max:114'],
            'ayah' => ['nullable', 'integer', 'min:1'],
        ]);

        $resolvedId = QiraatImportMaps::resolveReadingId($qiraat_reading_id);
        if (!$resolvedId) {
            return response()->json(['message' => 'Qiraat reading not found'], 404);
        }

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 15);
        $query = DB::table('qiraat_differences')
            ->where('qiraat_reading_id', $resolvedId)
            ->orderBy('surah')
            ->orderBy('ayah')
            ->orderBy('id');

        if (!empty($validated['surah'])) {
            $query->where('surah', (int) $validated['surah']);
        }
        if (!empty($validated['ayah'])) {
            $query->where('ayah', (int) $validated['ayah']);
        }

        $totalCount = $query->count();
        $totalPages = (int) max(1, ceil($totalCount / $perPage));
        $items = $query->forPage($page, $perPage)->get();

        if ($items->isEmpty()) {
            return $this->apiError('No qiraat differences found', 404);
        }

        return $this->apiSuccess([
            'meta' => [
                'total_count' => $totalCount,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'page_size' => $perPage,
            ],
            'result' => $items,
        ], 'Qiraat differences retrieved successfully');
    }
}
