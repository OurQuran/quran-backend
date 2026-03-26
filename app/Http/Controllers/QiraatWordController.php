<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QiraatWordController extends Controller
{
    /**
     * GET /qiraats/words/{id}/variants?is_mushaf=1|0&compare_qiraat_ids=1,2,3&include_same=1
     *
     * is_mushaf=1 => {id} is mushaf_words.id (current UI is a specific qiraat)
     * is_mushaf=0 => {id} is words.id (base)
     */
    public function variants(Request $request, int $id)
    {
        $validated = $request->validate([
            'is_mushaf' => ['required', 'in:0,1'],
            'compare_qiraat_ids' => ['nullable', 'string', 'max:500'],
            'include_same' => ['nullable', 'boolean'],
        ]);

        $isMushaf = ((int)$validated['is_mushaf']) === 1;
        $includeSame = (bool)($validated['include_same'] ?? true);

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

        // ------------------------------------------------------------
        // 1) Resolve base word ids + clicked context
        // ------------------------------------------------------------
        $clicked = null;
        $clickedGroupMushafWordIds = [];
        $clickedGroupWords = collect();
        $baseWordIds = [];
        $baseAyahIds = [];

        if ($isMushaf) {
            $clicked = DB::table('mushaf_words as mw')
                ->join('mushaf_ayahs as ma', 'ma.id', '=', 'mw.mushaf_ayah_id')
                ->select([
                    'mw.id as mushaf_word_id',
                    'mw.mushaf_ayah_id',
                    'mw.position',
                    'mw.word',
                    'mw.pure_word',
                    'mw.word_template',
                    'ma.qiraat_reading_id',
                    'ma.surah_id',
                    'ma.number_in_surah',
                    'ma.page',
                ])
                ->where('mw.id', $id)
                ->first();

            if (!$clicked) {
                return $this->apiError('mushaf_word not found', 404);
            }

            $clickedMaps = DB::table('mushaf_word_to_word_map')
                ->where('mushaf_word_id', $clicked->mushaf_word_id)
                ->get();

            if ($clickedMaps->isEmpty()) {
                return $this->apiSuccess([
                  //  'clicked' => $this->formatClicked($clicked, [(int)$clicked->mushaf_word_id], collect()),
                    'base' => [
                        'ayah_ids' => [],
                        'word_ids' => [],
                        'ayahs' => [],
                        'words' => [],
                        'note' => 'no_word_mapping',
                    ],
                    'variants' => [],
                ], 'Word variants retrieved successfully');
            }

            // Expand SPLIT group (same mushaf_ayah)
            $splitBaseWordIds = $clickedMaps
                ->where('map_type', 'split')
                ->pluck('word_id')
                ->unique()
                ->values()
                ->all();

            $clickedGroupMushafWordIds = [(int)$clicked->mushaf_word_id];

            if (!empty($splitBaseWordIds)) {
                $extra = DB::table('mushaf_word_to_word_map as m')
                    ->join('mushaf_words as mw', 'mw.id', '=', 'm.mushaf_word_id')
                    ->where('mw.mushaf_ayah_id', $clicked->mushaf_ayah_id)
                    ->where('m.map_type', 'split')
                    ->whereIn('m.word_id', $splitBaseWordIds)
                    ->pluck('m.mushaf_word_id')
                    ->unique()
                    ->values()
                    ->all();

                $clickedGroupMushafWordIds = array_values(array_unique(array_merge($clickedGroupMushafWordIds, $extra)));
            }

            // Base word ids for this clicked "unit"
            $groupMaps = DB::table('mushaf_word_to_word_map')
                ->whereIn('mushaf_word_id', $clickedGroupMushafWordIds)
                ->get();

            $baseWordIds = $groupMaps->pluck('word_id')->unique()->values()->all();

            $clickedGroupWords = DB::table('mushaf_words')
                ->whereIn('id', $clickedGroupMushafWordIds)
                ->orderBy('position')
                ->get(['id', 'position', 'word', 'pure_word', 'word_template']);

            // Base ayah ids anchor
            $baseAyahIds = DB::table('mushaf_ayah_to_ayah_map')
                ->where('mushaf_ayah_id', $clicked->mushaf_ayah_id)
                ->pluck('ayah_id')
                ->unique()
                ->values()
                ->all();
        } else {
            // base word
            $base = DB::table('words')->where('id', $id)->first(['id', 'word', 'pure_word']);
            if (!$base) {
                return $this->apiError('base word not found', 404);
            }

            $baseWordIds = [(int)$base->id];

            // derive base ayah anchor via mappings (keeps results small & correct)
            $baseAyahIds = DB::table('mushaf_word_to_word_map as m')
                ->join('mushaf_words as mw', 'mw.id', '=', 'm.mushaf_word_id')
                ->join('mushaf_ayah_to_ayah_map as amap', 'amap.mushaf_ayah_id', '=', 'mw.mushaf_ayah_id')
                ->where('m.word_id', $base->id)
                ->pluck('amap.ayah_id')
                ->unique()
                ->values()
                ->all();

            // Optional: provide a clicked example context (first mushaf occurrence)
            $example = DB::table('mushaf_word_to_word_map as m')
                ->join('mushaf_words as mw', 'mw.id', '=', 'm.mushaf_word_id')
                ->join('mushaf_ayahs as ma', 'ma.id', '=', 'mw.mushaf_ayah_id')
                ->where('m.word_id', $base->id)
                ->orderBy('ma.qiraat_reading_id')
                ->orderBy('mw.mushaf_ayah_id')
                ->orderBy('mw.position')
                ->first([
                    'mw.id as mushaf_word_id',
                    'mw.mushaf_ayah_id',
                    'mw.position',
                    'mw.word',
                    'mw.pure_word',
                    'mw.word_template',
                    'ma.qiraat_reading_id',
                    'ma.surah_id',
                    'ma.number_in_surah',
                    'ma.page',
                ]);

            if ($example) {
                $clicked = (object)$example;
                $clickedGroupMushafWordIds = [(int)$example->mushaf_word_id];
                $clickedGroupWords = collect([$example]);
            }
        }

        // Load base rows for response clarity
        $baseWords = $this->loadBaseWords($baseWordIds);

        if (empty($baseAyahIds)) {
            return $this->apiSuccess([
                // 'clicked' => $clicked ? $this->formatClicked($clicked, $clickedGroupMushafWordIds, $clickedGroupWords) : null,
                'base' => [
                    'ayah_ids' => [],
                    'word_ids' => $baseWordIds,
                    'ayahs' => [],
                    'words' => $baseWords,
                    'note' => 'no_ayah_anchor_found_for_this_word',
                ],
                'variants' => [],
            ], 'Word variants retrieved successfully');
        }

        $baseAyahs = $this->loadBaseAyahs($baseAyahIds);

        // ------------------------------------------------------------
        // 2) Find all mushaf_ayahs across readings for those base ayah ids
        // ------------------------------------------------------------
        $allAyahsQuery = DB::table('mushaf_ayah_to_ayah_map as map')
            ->join('mushaf_ayahs as ma', 'ma.id', '=', 'map.mushaf_ayah_id')
            ->select(['ma.id', 'ma.qiraat_reading_id'])
            ->whereIn('map.ayah_id', $baseAyahIds)
            ->distinct();

        if ($compareIds) {
            $allAyahsQuery->whereIn('ma.qiraat_reading_id', $compareIds);
        }

        $mushafAyahIds = $allAyahsQuery->pluck('ma.id')->values()->all();

        if (empty($mushafAyahIds)) {
            return $this->apiSuccess([
                // 'clicked' => $clicked ? $this->formatClicked($clicked, $clickedGroupMushafWordIds, $clickedGroupWords) : null,
                'base' => [
                    'ayah_ids' => $baseAyahIds,
                    'word_ids' => $baseWordIds,
                    'ayahs' => $baseAyahs,
                    'words' => $baseWords,
                    'note' => 'no_related_mushaf_ayahs',
                ],
                'variants' => [],
            ], 'Word variants retrieved successfully');
        }

        // clicked pure sequence (only meaningful when we have a clicked mushaf context)
        $clickedPureKey = $clickedGroupWords
            ->map(fn ($w) => (string)($w->pure_word ?? ''))
            ->filter(fn ($x) => $x !== '')
            ->implode(' ');

        // ------------------------------------------------------------
        // 3) Pull all mushaf words in those ayahs that map to baseWordIds
        // ------------------------------------------------------------
        $rows = DB::table('mushaf_ayahs as ma')
            ->join('mushaf_words as mw', 'mw.mushaf_ayah_id', '=', 'ma.id')
            ->join('mushaf_word_to_word_map as m', 'm.mushaf_word_id', '=', 'mw.id')
            ->whereIn('ma.id', $mushafAyahIds)
            ->whereIn('m.word_id', $baseWordIds)
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
                'm.match_method',
                'm.confidence',
            ]);

        $variants = [];

        foreach ($rows->groupBy('qiraat_reading_id') as $qiraatId => $items) {
            $items = $items->values();

            $byWord = $items->groupBy('mushaf_word_id')->map(function ($g) {
                $first = $g->first();
                return [
                    'mushaf_word_id' => (int)$first->mushaf_word_id,
                    'position' => (int)$first->position,
                    'word' => $first->word,
                    'pure_word' => $first->pure_word,
                    'word_template' => $first->word_template,
                ];
            })->values()->sortBy('position')->values();

            $pureKey = $byWord
                ->map(fn ($w) => (string)($w['pure_word'] ?? ''))
                ->filter(fn ($x) => $x !== '')
                ->implode(' ');

            $hasKnownDiff = $items->contains(fn ($r) => !is_null($r->qiraat_difference_id));
            $samePure = ($clickedPureKey !== '') ? ($pureKey === $clickedPureKey) : null;
            $different = $hasKnownDiff || ($samePure === false);

            if (!$includeSame && $samePure === true && !$hasKnownDiff) {
                continue;
            }

            $variants[] = [
                'qiraat_reading_id' => (int)$qiraatId,
                'mushaf_ayah_id' => (int)$items->first()->mushaf_ayah_id,
                'unit' => [
                    'mushaf_word_ids' => $byWord->pluck('mushaf_word_id')->values()->all(),
                    'words' => $byWord->pluck('word')->values()->all(),
                    'pure_words' => $byWord->pluck('pure_word')->values()->all(),
                ],
                'flags' => [
                    'known_diff' => $hasKnownDiff,
                    'same_pure' => $samePure,
                    'different' => $different,
                ],
            ];
        }

        // clicked reading first (if we have it)
        if ($clicked && isset($clicked->qiraat_reading_id)) {
            usort($variants, function ($a, $b) use ($clicked) {
                if ($a['qiraat_reading_id'] === (int)$clicked->qiraat_reading_id) return -1;
                if ($b['qiraat_reading_id'] === (int)$clicked->qiraat_reading_id) return 1;
                return $a['qiraat_reading_id'] <=> $b['qiraat_reading_id'];
            });
        }

        return $this->apiSuccess([
            // 'clicked' => $clicked ? $this->formatClicked($clicked, $clickedGroupMushafWordIds, $clickedGroupWords) : null,
            'base' => [
                'ayah_ids' => $baseAyahIds,
                'word_ids' => $baseWordIds,
                'ayahs' => $baseAyahs,
                'words' => $baseWords,
            ],
            'variants' => $variants,
        ], 'Word variants retrieved successfully');
    }

    private function formatClicked(object $clicked, array $groupIds, $groupWords): array
    {
        $words = $groupWords instanceof \Illuminate\Support\Collection ? $groupWords : collect([]);

        return [
            'mushaf_word_id' => (int)$clicked->mushaf_word_id,
            'qiraat_reading_id' => (int)$clicked->qiraat_reading_id,
            'mushaf_ayah_id' => (int)$clicked->mushaf_ayah_id,
            'surah_id' => (int)$clicked->surah_id,
            'number_in_surah' => (int)$clicked->number_in_surah,
            'page' => $clicked->page,
            'position' => (int)$clicked->position,
            'word' => $clicked->word,
            'pure_word' => $clicked->pure_word,
            'word_template' => $clicked->word_template,
            'group' => [
                'mushaf_word_ids' => array_values($groupIds),
                'words' => $words->pluck('word')->values()->all(),
                'pure_words' => $words->pluck('pure_word')->values()->all(),
            ],
        ];
    }

    private function loadBaseWords(array $wordIds)
    {
        if (empty($wordIds)) return [];
        return DB::table('words')
            ->whereIn('id', $wordIds)
            ->orderBy('id')
            ->get(['id', 'word', 'pure_word']);
    }

    private function loadBaseAyahs(array $ayahIds)
    {
        if (empty($ayahIds)) return [];
        // adjust columns if your ayahs table uses different names
        return DB::table('ayahs')
            ->whereIn('id', $ayahIds)
            ->orderBy('id')
            ->get(['id', 'surah_id', 'number_in_surah', 'text', 'pure_text', 'page']);
    }
}
