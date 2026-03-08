<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QiraatAyahController extends Controller
{
    /**
     * GET /qiraats/ayahs/{id}/differences?is_mushaf=1|0&compare_qiraat_ids=...
     *
     * is_mushaf=1 => {id} is mushaf_ayahs.id
     * is_mushaf=0 => {id} is ayahs.id (base)
     */
    public function differences(Request $request, int $id)
    {
        $validated = $request->validate([
            'is_mushaf' => ['required', 'in:0,1'],
            'compare_qiraat_ids' => ['nullable', 'string', 'max:500'],
        ]);

        $isMushaf = ((int)$validated['is_mushaf']) === 1;

        $compareIds = null;
        if (!empty($validated['compare_qiraat_ids'])) {
            $compareIds = collect(explode(',', $validated['compare_qiraat_ids']))
                ->map(fn ($x) => (int)trim($x))
                ->filter(fn ($x) => $x > 0)
                ->unique()
                ->values()
                ->all();
            if (empty($compareIds)) $compareIds = null;
        }

        $refMushafAyah = null;
        $baseAyahIds = [];

        if ($isMushaf) {
            $refMushafAyah = DB::table('mushaf_ayahs')->where('id', $id)->first();
            if (!$refMushafAyah) return response()->json(['message' => 'mushaf_ayah not found'], 404);

            $baseAyahIds = DB::table('mushaf_ayah_to_ayah_map')
                ->where('mushaf_ayah_id', $id)
                ->pluck('ayah_id')
                ->unique()
                ->values()
                ->all();
        } else {
            $baseExists = DB::table('ayahs')->where('id', $id)->exists();
            if (!$baseExists) return response()->json(['message' => 'base ayah not found'], 404);

            $baseAyahIds = [(int)$id];

            // pick a mushaf reference for baseline UI (first reading)
            $refMushafAyah = DB::table('mushaf_ayah_to_ayah_map as map')
                ->join('mushaf_ayahs as ma', 'ma.id', '=', 'map.mushaf_ayah_id')
                ->where('map.ayah_id', $id)
                ->orderBy('ma.qiraat_reading_id')
                ->first(['ma.*']);
        }

        if (empty($baseAyahIds)) {
            return response()->json([
                'input' => ['is_mushaf' => $isMushaf ? 1 : 0, 'id' => (int)$id],
                'base' => ['ayah_ids' => [], 'ayahs' => []],
                'readings' => [],
            ]);
        }

        $baseAyahs = DB::table('ayahs')
            ->whereIn('id', $baseAyahIds)
            ->orderBy('id')
            ->get(['id', 'surah_id', 'number_in_surah', 'text', 'pure_text', 'page']);

        // all mushaf ayahs across readings for these base ayah ids
        $ayahsQuery = DB::table('mushaf_ayah_to_ayah_map as map')
            ->join('mushaf_ayahs as ma', 'ma.id', '=', 'map.mushaf_ayah_id')
            ->select([
                'ma.id',
                'ma.qiraat_reading_id',
                'ma.text',
                'ma.pure_text',
                'ma.ayah_template',
                'ma.page',
                'ma.surah_id',
                'ma.number_in_surah',
            ])
            ->whereIn('map.ayah_id', $baseAyahIds)
            ->distinct()
            ->orderBy('ma.qiraat_reading_id');

        if ($compareIds) {
            $ayahsQuery->whereIn('ma.qiraat_reading_id', $compareIds);
        }

        $ayahs = $ayahsQuery->get();
        $mushafAyahIds = $ayahs->pluck('id')->values()->all();

        if (empty($mushafAyahIds)) {
            return response()->json([
                'input' => ['is_mushaf' => $isMushaf ? 1 : 0, 'id' => (int)$id],
                'base' => ['ayah_ids' => $baseAyahIds, 'ayahs' => $baseAyahs],
                'readings' => [],
            ]);
        }

        // words + mapping
        $rows = DB::table('mushaf_ayahs as ma')
            ->join('mushaf_words as mw', 'mw.mushaf_ayah_id', '=', 'ma.id')
            ->leftJoin('mushaf_word_to_word_map as m', 'm.mushaf_word_id', '=', 'mw.id')
            ->whereIn('ma.id', $mushafAyahIds)
            ->orderBy('ma.qiraat_reading_id')
            ->orderBy('mw.position')
            ->orderByRaw('m.word_order NULLS LAST')
            ->get([
                'ma.id as mushaf_ayah_id',
                'ma.qiraat_reading_id',
                'mw.id as mushaf_word_id',
                'mw.position',
                'mw.word',
                'mw.pure_word',
                'mw.word_template',
                'm.word_id',
                'm.map_type',
                'm.part_no',
                'm.parts_total',
                'm.word_order',
                'm.qiraat_difference_id',
            ]);

        $byReading = $rows->groupBy('qiraat_reading_id');

        $baselineQiraatId = $refMushafAyah ? (int)$refMushafAyah->qiraat_reading_id : (int)$byReading->keys()->first();
        if (!$byReading->has($baselineQiraatId)) {
            $baselineQiraatId = (int)$byReading->keys()->first();
        }

        $baselineWords = $this->collapseWords($byReading->get($baselineQiraatId, collect()));
        $baselinePureKey = implode(' ', array_map(fn ($w) => (string)($w['pure_word'] ?? ''), $baselineWords));

        $readings = [];
        foreach ($ayahs as $a) {
            $qiraatId = (int)$a->qiraat_reading_id;
            $items = $byReading->get($qiraatId, collect());
            $words = $this->collapseWords($items);

            $pureKey = implode(' ', array_map(fn ($w) => (string)($w['pure_word'] ?? ''), $words));

            $knownDiffCount = 0;
            foreach ($words as $w) {
                if (!empty($w['known_difference_ids'])) $knownDiffCount++;
            }

            $readings[] = [
                'qiraat_reading_id' => $qiraatId,
                'mushaf_ayah_id' => (int)$a->id,
                'page' => $a->page,
                'text' => $a->text,
                'pure_text' => $a->pure_text,
                'ayah_template' => $a->ayah_template,
                'summary' => [
                    'same_as_baseline_pure' => ($pureKey === $baselinePureKey),
                    'known_diff_words' => $knownDiffCount,
                ],
                'words' => $words,
            ];
        }

        return response()->json([
            'input' => ['is_mushaf' => $isMushaf ? 1 : 0, 'id' => (int)$id],
            'meta' => [
                'baseline_qiraat_reading_id' => $baselineQiraatId,
            ],
            'base' => [
                'ayah_ids' => $baseAyahIds,
                'ayahs' => $baseAyahs,
            ],
            'readings' => $readings,
        ]);
    }

    private function collapseWords($items): array
    {
        if ($items->isEmpty()) return [];

        $byWord = $items->groupBy('mushaf_word_id')->map(function ($g) {
            $first = $g->first();

            $maps = $g->filter(fn ($r) => !is_null($r->word_id))->map(fn ($r) => [
                'word_id' => (int)$r->word_id,
                'map_type' => $r->map_type,
                'part_no' => $r->part_no,
                'parts_total' => $r->parts_total,
                'word_order' => $r->word_order,
                'qiraat_difference_id' => $r->qiraat_difference_id,
            ])->values()->all();

            $knownIds = collect($maps)->pluck('qiraat_difference_id')->filter()->unique()->values()->all();

            return [
                'mushaf_word_id' => (int)$first->mushaf_word_id,
                'position' => (int)$first->position,
                'word' => $first->word,
                'pure_word' => $first->pure_word,
                'word_template' => $first->word_template,
                'maps' => $maps,
                'known_difference_ids' => $knownIds,
                'flags' => [
                    'known_diff' => !empty($knownIds),
                    'has_mapping' => !empty($maps),
                ],
            ];
        });

        return $byWord->values()->sortBy('position')->values()->all();
    }
}
