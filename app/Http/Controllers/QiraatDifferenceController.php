<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Paginated list of qiraat_differences for a given qiraat reading.
 * GET /qiraats/{qiraat_reading_id}/differences?page=1&per_page=15&surah=1&ayah=2
 */
class QiraatDifferenceController extends Controller
{
    /**
     * List qiraat_differences for a qiraat reading (paginated).
     * Optional filters: surah, ayah.
     */
    public function index(Request $request, int $qiraat_reading_id)
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'surah' => ['nullable', 'integer', 'min:1', 'max:114'],
            'ayah' => ['nullable', 'integer', 'min:1'],
        ]);

        $exists = DB::table('qiraat_readings')->where('id', $qiraat_reading_id)->exists();
        if (!$exists) {
            return response()->json(['message' => 'Qiraat reading not found'], 404);
        }

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 15);
        $query = DB::table('qiraat_differences')
            ->where('qiraat_reading_id', $qiraat_reading_id)
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
