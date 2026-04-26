<?php

namespace App\Http\Controllers;

use App\Http\Requests\SurahRequest;
use App\Models\Ayah;
use App\Models\Edition;
use App\Models\MushafAyah;
use App\Models\QiraatReading;
use App\Models\Surah;
use App\Models\User;
use App\Support\QiraatImportMaps;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class QuranController extends Controller
{

    // todo: Edition Languages (todo, group by the language identifier for easier frontend fetch)
    public function languages()
    {
        try {
            $editions = Edition::query()->select('language')->get();

            return $this->apiSuccess($editions, 'Languages retrieved successfully');
        } catch (Exception $e) {
            return $this->apiError('Failed to retrieve languages: ' . $e->getMessage());
        }
    }
    public function readings(): JsonResponse
    {
        try {
            $readings = QiraatReading::query()
                ->select(['id', 'code', 'imam', 'riwaya', 'name'])
                ->orderBy('id')
                ->get();

            return $this->apiSuccess($readings, 'Qiraat readings retrieved successfully');
        } catch (Exception $e) {
            return $this->apiError('Failed to retrieve qiraat readings: ' . $e->getMessage());
        }
    }

    public function editions(): JsonResponse
    {
        try {
            $editions = Edition::query()->get();
            return $this->apiSuccess($editions, 'Editions retrieved successfully');
        } catch (Exception $e) {
            return $this->apiError('Failed to retrieve editions');
        }
    }

    public function surahsIndex(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'surah' => 'sometimes|nullable|int',
                'type' => [
                    'sometimes',
                    'nullable',
                    function ($attribute, $value, $fail) {
                        if ($value !== '' && !in_array(strtolower($value), ['meccan', 'medinan'])) {
                            $fail("The $attribute must be either 'meccan' or 'medinan'.");
                        }
                    }
                ],
                'revelation_order' => [
                    'sometimes',
                    'nullable',
                    function ($attribute, $value, $fail) {
                        if ($value !== '' && !in_array(strtolower($value), ['asc', 'desc'])) {
                            $fail("The $attribute must be either 'asc' or 'desc'.");
                        }
                    }
                ],
                'name' => 'sometimes|nullable|string',
            ]);

            $query = Surah::query()
                ->leftJoin('ayahs', function ($join) {
                    $join->on('surahs.id', '=', 'ayahs.surah_id')
                        ->whereRaw('ayahs.number_in_surah = (SELECT MIN(number_in_surah) FROM ayahs WHERE ayahs.surah_id = surahs.id)');
                })
                ->select(
                    'surahs.id',
                    'surahs.number',
                    'surahs.name_ar',
                    'surahs.name_en',
                    'surahs.name_en_translation',
                    'surahs.type',
                    'ayahs.juz_id'
                );

            if (!empty($validated['surah'])) {
                $surah = $query->where('surahs.id', $validated['surah'])->firstOrFail();
                return $this->apiSuccess($surah, 'Surah retrieved successfully');
            }

            if (!empty($validated['type'])) {
                $query->where('surahs.type', 'ILIKE', "%{$validated['type']}%");
            }

            if (!empty($validated['revelation_order'])) {
                $query->orderBy('surahs.number', $validated['revelation_order']);
            }

            if (!empty($validated['name'])) {
                $query->where(function ($q) use ($validated) {
                    $q->where('surahs.name_en', 'ILIKE', "%{$validated['name']}%")
                        ->orWhere('surahs.name_ar', 'ILIKE', "%{$validated['name']}%")
                        ->orWhere('surahs.name_en_translation', 'ILIKE', "%{$validated['name']}%");
                });
            }

            $surahs = $query->orderBy('surahs.id')->get();

            return $this->apiSuccess($surahs, 'Surahs retrieved successfully');
        } catch (Exception $e) {
            return $this->apiError("Failed to retrieve Surahs: " . $e->getMessage());
        }
    }

    public function getByPage(Request $request, int $page): JsonResponse
    {
        try {
            $validated = $request->validate([
                // pagination page (not the mushaf/base page route param)
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
                'verse' => 'sometimes|integer|min:0',
                'text_edition' => 'sometimes|integer|exists:editions,id',
                'audio_edition' => 'sometimes|integer|exists:editions,id',
                'qiraat_reading_id' => 'sometimes|integer|exists:qiraat_readings,id',
            ]);

            $validated = $this->withDefaults($validated);
            $user = $this->checkLoginToken();

            $currentPage = (int) $validated['page'];
            $perPage = (int) $validated['per_page'];
            $qiraatReadingId = (int) $validated['qiraat_reading_id'];

            // =========================
            // MUSHAF MODE (qiraat > 1)
            // =========================
            if (!$this->usesBaseAyahs($qiraatReadingId)) {
                // total mushaf ayahs on that mushaf page for that qiraat
                $totalCount = DB::table('mushaf_ayahs as ma')
                    ->where('ma.qiraat_reading_id', $qiraatReadingId)
                    ->where('ma.page', $page)
                    ->count();

                if ($totalCount === 0) {
                    return $this->apiError('Invalid page number or no matching Ayahs', 404);
                }

                // Pick EXACTLY ONE map row per mushaf ayah using LATERAL
                // Preference: exact first, then part_no, then ayah_id
                $lateral = DB::raw("
                LATERAL (
                    SELECT map.*
                    FROM mushaf_ayah_to_ayah_map map
                    WHERE map.mushaf_ayah_id = ma.id
                    ORDER BY
                      CASE WHEN map.map_type = 'exact' THEN 0 ELSE 1 END,
                      COALESCE(map.part_no, 0),
                      map.ayah_id
                    LIMIT 1
                ) as map
            ");

                $query = DB::table('mushaf_ayahs as ma')
                    ->leftJoin($lateral, DB::raw('TRUE'), DB::raw('TRUE'))
                    ->leftJoin('ayah_edition as text_ae', function ($join) use ($validated) {
                        $join->on('text_ae.ayah_id', '=', 'map.ayah_id')
                            ->where('text_ae.edition_id', '=', (int) $validated['text_edition']);
                    })
                    ->leftJoin('ayah_edition as audio_ae', function ($join) use ($validated) {
                        $join->on('audio_ae.ayah_id', '=', 'map.ayah_id')
                            ->where('audio_ae.edition_id', '=', (int) $validated['audio_edition']);
                    });

                // bookmarks (optional)
                if ($user) {
                    $query->leftJoin('bookmarks as bm', function ($join) use ($user) {
                        $join->on('bm.ayah_id', '=', 'map.ayah_id')
                            ->where('bm.user_id', '=', $user->id);
                    });
                }

                // optional verse filter in mushaf mode (by mushaf number_in_surah)
                if (!empty($validated['verse']) && (int)$validated['verse'] !== 0) {
                    $query->where('ma.number_in_surah', (int)$validated['verse']);
                }

                $rows = $query
                    ->where('ma.qiraat_reading_id', $qiraatReadingId)
                    ->where('ma.page', $page)
                    ->orderBy('ma.surah_id')
                    ->orderBy('ma.page')
                    ->orderBy('ma.number_in_surah')
                    ->select([
                        // Make id be mushaf id to avoid duplicates / confusion
                        'ma.id as id',

                        // Keep explicit ids too
                        'ma.id as mushaf_ayah_id',
                        DB::raw('map.ayah_id as base_ayah_id'),

                        // mushaf fields as the primary representation
                        'ma.surah_id',
                        'ma.page',
                        'ma.juz_id',
                        'ma.hizb_id',
                        'ma.sajda',
                        'ma.number_in_surah',
                        'ma.text',
                        'ma.pure_text',
                        'ma.ayah_template',
                        DB::raw('(SELECT s.name_ar FROM surahs s WHERE s.id = ma.surah_id LIMIT 1) AS surah_name_ar'),
                        DB::raw('(SELECT s.name_en FROM surahs s WHERE s.id = ma.surah_id LIMIT 1) AS surah_name_en'),

                        DB::raw('COALESCE(text_ae.data, NULL) AS translation'),
                        DB::raw('COALESCE(audio_ae.data, NULL) AS audio'),

                        $user
                            ? DB::raw('CASE WHEN bm.ayah_id IS NOT NULL THEN TRUE ELSE FALSE END AS bookmarked')
                            : DB::raw('FALSE AS bookmarked'),
                    ])
                    ->skip(($currentPage - 1) * $perPage)
                    ->take($perPage)
                    ->get();

                // shape response
                $ayahs = collect($rows)->map(function ($r) use ($user) {
                    $r->template = $r->ayah_template;
                    unset($r->ayah_template, $r->mushaf_ayah_id);
                    $r->tags = $this->getTagsForAyahId(
                        $r->base_ayah_id !== null ? (int) $r->base_ayah_id : null,
                        $user
                    );

                    return $r;
                });

                $totalPages = (int) ceil($totalCount / $perPage);

                return $this->apiSuccess([
                    'meta' => [
                        'total_count' => $totalCount,
                        'total_pages' => $totalPages,
                        'current_page' => $currentPage,
                        'per_page' => $perPage,
                    ],
                    'ayahs' => $ayahs,
                ], 'Page retrieved successfully');
            }

            // =========================
            // BASE MODE (qiraat = 1)
            // =========================
            $totalCount = Ayah::query()
                ->where('ayahs.page', $page)
                ->count();

            if ($totalCount === 0) {
                return $this->apiError('Invalid page number or no matching Ayahs', 404);
            }

            $ayahsQuery = Ayah::query()
                ->where('ayahs.page', $page)
                ->orderBy('ayahs.surah_id')
                ->orderBy('ayahs.page')
                ->orderBy('ayahs.number_in_surah')
                ->skip(($currentPage - 1) * $perPage)
                ->take($perPage);

            $ayahs = $this->filterAyahs($validated, $ayahsQuery);

            if ($ayahs->isEmpty() && $currentPage === 1) {
                return $this->apiError('Invalid page number or no matching Ayahs', 404);
            }

            $bismillahRow = $this->getBismillahRow($validated);
            $modified = $this->injectBismillahPerAyahList($ayahs, $bismillahRow, $user, mode: 'page');

            $totalPages = (int) ceil($totalCount / $perPage);

            return $this->apiSuccess([
                'meta' => [
                    'total_count' => $totalCount,
                    'total_pages' => $totalPages,
                    'current_page' => $currentPage,
                    'per_page' => $perPage,
                ],
                'ayahs' => $modified,
            ], 'Page retrieved successfully');
        } catch (Exception $e) {
            return $this->apiError('Failed to retrieve page: ' . $e->getMessage());
        }
    }


    /**
     * GET /.../juz/{juz}
     */
    public function getByJuz(Request $request, int $juz): JsonResponse
    {
        try {
            $validated = $request->validate([
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
                'verse' => 'sometimes|integer|min:0',
                'text_edition' => 'sometimes|integer|exists:editions,id',
                'audio_edition' => 'sometimes|integer|exists:editions,id',
                'qiraat_reading_id' => 'sometimes|integer|exists:qiraat_readings,id',
            ]);

            $validated = $this->withDefaults($validated);
            $user = $this->checkLoginToken();

            $page = (int)$validated['page'];
            $perPage = (int)$validated['per_page'];

            $totalCount = Ayah::query()->where('juz_id', $juz)->count();
            if ($totalCount === 0) {
                return $this->apiError('Invalid Juz number or no matching Ayahs', 404);
            }

            $ayahsQuery = Ayah::query()
                ->where('ayahs.juz_id', $juz)
                ->orderBy('ayahs.surah_id')
                ->orderBy('ayahs.juz_id')
                ->orderBy('ayahs.number_in_surah')
                ->skip(($page - 1) * $perPage)
                ->take($perPage);

            $ayahs = $this->filterAyahs($validated, $ayahsQuery);

            if ($ayahs->isEmpty() && $page === 1) {
                return $this->apiError('Invalid Juz number or no matching Ayahs', 404);
            }

            $bismillahRow = $this->getBismillahRow($validated);
            $modified = $this->injectBismillahPerAyahList($ayahs, $bismillahRow, $user, mode: 'juz');

            $totalPages = (int)ceil($totalCount / $perPage);

            return $this->apiSuccess([
                'meta' => [
                    'total_count' => $totalCount,
                    'total_pages' => $totalPages,
                    'current_page' => $page,
                    'per_page' => $perPage,
                ],
                'ayahs' => $modified,
            ], 'Juz retrieved successfully');
        } catch (Exception $e) {
            return $this->apiError("Failed to retrieve Juz: " . $e->getMessage());
        }
    }

    /**
     * GET /.../surahs/{surah}
     */
    public function getBySurah(Request $request, int $surah): JsonResponse
    {
        try {
            $validated = $request->validate([
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
                'verse' => 'sometimes|integer|min:0',
                'text_edition' => 'sometimes|integer|exists:editions,id',
                'audio_edition' => 'sometimes|integer|exists:editions,id',
                'qiraat_reading_id' => 'sometimes|integer|exists:qiraat_readings,id',
            ]);

            $validated = $this->withDefaults($validated);
            $user = $this->checkLoginToken();

            $page = (int) $validated['page'];
            $perPage = (int) $validated['per_page'];
            $qiraatReadingId = (int) $validated['qiraat_reading_id'];

            $useMushaf = !$this->usesBaseAyahs($qiraatReadingId);

            if ($useMushaf) {
                $queryBase = DB::table('mushaf_ayahs as ma')
                    ->where('ma.qiraat_reading_id', $qiraatReadingId)
                    ->where('ma.surah_id', $surah);

                if (!empty($validated['verse']) && (int) $validated['verse'] !== 0) {
                    $queryBase->where('ma.number_in_surah', (int) $validated['verse']);
                }

                $totalCount = (clone $queryBase)->count();

                if ($totalCount === 0) {
                    return $this->apiError('Invalid Surah number or no matching Ayahs', 404);
                }

                $totalPages = (int) ceil($totalCount / $perPage);

                if ($page > $totalPages) {
                    return $this->apiError('Page out of range', 404);
                }

                $lateral = DB::raw("
        LATERAL (
            SELECT map.*
            FROM mushaf_ayah_to_ayah_map map
            WHERE map.mushaf_ayah_id = ma.id
            ORDER BY
                CASE WHEN map.map_type = 'exact' THEN 0 ELSE 1 END,
                COALESCE(map.part_no, 0),
                COALESCE(map.ayah_order, 0),
                map.ayah_id
            LIMIT 1
        ) as map
    ");

                $query = DB::table('mushaf_ayahs as ma')
                    ->leftJoin($lateral, DB::raw('TRUE'), DB::raw('TRUE'))
                    ->leftJoin('ayah_edition as text_ae', function ($join) use ($validated) {
                        $join->on('text_ae.ayah_id', '=', 'map.ayah_id')
                            ->where('text_ae.edition_id', '=', (int) $validated['text_edition']);
                    })
                    ->leftJoin('ayah_edition as audio_ae', function ($join) use ($validated) {
                        $join->on('audio_ae.ayah_id', '=', 'map.ayah_id')
                            ->where('audio_ae.edition_id', '=', (int) $validated['audio_edition']);
                    })
                    ->where('ma.qiraat_reading_id', $qiraatReadingId)
                    ->where('ma.surah_id', $surah);

                if (!empty($validated['verse']) && (int) $validated['verse'] !== 0) {
                    $query->where('ma.number_in_surah', (int) $validated['verse']);
                }

                if ($user) {
                    $query->leftJoin('bookmarks as bm', function ($join) use ($user) {
                        $join->on('bm.ayah_id', '=', 'map.ayah_id')
                            ->where('bm.user_id', '=', $user->id);
                    });
                }

                $rows = $query
                    ->orderBy('ma.number_in_surah')
                    ->select([
                        'ma.id as id',
                        'ma.id as mushaf_ayah_id',
                        DB::raw('map.ayah_id as base_ayah_id'),

                        'ma.surah_id',
                        'ma.page',
                        'ma.juz_id',
                        'ma.hizb_id',
                        'ma.sajda',
                        'ma.number_in_surah',
                        'ma.text',
                        'ma.pure_text',

                        // Important: expose qiraat template in BOTH fields
                        'ma.ayah_template as ayah_template',
                        'ma.ayah_template as template',

                        DB::raw('(SELECT s.name_ar FROM surahs s WHERE s.id = ma.surah_id LIMIT 1) AS surah_name_ar'),
                        DB::raw('(SELECT s.name_en FROM surahs s WHERE s.id = ma.surah_id LIMIT 1) AS surah_name_en'),

                        DB::raw('text_ae.data AS translation'),
                        DB::raw('audio_ae.data AS audio'),

                        $user
                            ? DB::raw('CASE WHEN bm.ayah_id IS NOT NULL THEN TRUE ELSE FALSE END AS bookmarked')
                            : DB::raw('FALSE AS bookmarked'),
                    ])
                    ->skip(($page - 1) * $perPage)
                    ->take($perPage)
                    ->get();

                $ayahs = collect($rows)->map(function ($r) use ($user) {
                    $r->tags = $this->getTagsForAyahId(
                        $r->base_ayah_id !== null ? (int) $r->base_ayah_id : null,
                        $user
                    );

                    unset($r->mushaf_ayah_id);

                    return $r;
                });

                return $this->apiSuccess([
                    'meta' => [
                        'total_count' => $totalCount,
                        'total_pages' => $totalPages,
                        'current_page' => $page,
                        'per_page' => $perPage,
                    ],
                    'ayahs' => $ayahs,
                ], 'Surah retrieved successfully');
            }

            // fallback: base ayahs
            $totalCount = Ayah::query()->where('ayahs.surah_id', $surah)->count();
            if ($totalCount === 0) {
                return $this->apiError('Invalid Surah number or no matching Ayahs', 404);
            }

            $ayahsQuery = Ayah::query()
                ->where('ayahs.surah_id', $surah)
                ->orderBy('ayahs.juz_id')
                ->orderBy('ayahs.page')
                ->orderBy('ayahs.number_in_surah')
                ->skip(($page - 1) * $perPage)
                ->take($perPage);

            $ayahs = $this->filterAyahs($validated, $ayahsQuery);

            if ($ayahs->isEmpty() && $page === 1) {
                return $this->apiError('Invalid Surah number or no matching Ayahs', 404);
            }

            $modifiedAyahs = collect();

            if ($page === 1 && $surah !== 1 && $surah !== 9) {
                $bismillahRow = $this->getBismillahRow($validated);
                if ($bismillahRow) {
                    $b = clone $bismillahRow;
                    $b->surah_id = $surah;
                    $b->number_in_surah = 0;
                    $this->applySurahNames($b);
                    $b->tags = $this->getTagsForAyah($b, $user);
                    $modifiedAyahs->push($b);
                }
            }

            foreach ($ayahs as $ayah) {
                $ayah->tags = $this->getTagsForAyah($ayah, $user);
                $modifiedAyahs->push($ayah);
            }

            $totalPages = (int) ceil($totalCount / $perPage);

            return $this->apiSuccess([
                'meta' => [
                    'total_count' => $totalCount,
                    'total_pages' => $totalPages,
                    'current_page' => $page,
                    'per_page' => $perPage,
                ],
                'ayahs' => $modifiedAyahs,
            ], 'Surah retrieved successfully');
        } catch (Exception $e) {
            return $this->apiError("Failed to retrieve Surah: " . $e->getMessage());
        }
    }

    /* ============================================================
     |  SEARCH (unified)
     * ============================================================ */

    /**
     * GET /.../search?q=...&type=exact|semantic
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'q' => 'required|string',
                'type' => 'required|string|in:exact,semantic',
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
                'text_edition' => 'sometimes|integer|exists:editions,id',
                'audio_edition' => 'sometimes|integer|exists:editions,id',
                'qiraat_reading_id' => 'sometimes|integer|exists:qiraat_readings,id',
            ]);

            $validated = $this->withDefaults($validated);

            $page = (int)$validated['page'];
            $perPage = (int)$validated['per_page'];

            $result = Http::post(
                rtrim((string)env('AI_URL'), '/') . "/{$validated['type']}_search",
                ['query' => $validated['q']]
            );

            $json = json_decode((string)$result->body());

            if (!$json || !isset($json->ayah_ids) || !is_array($json->ayah_ids)) {
                return $this->apiError('AI search service returned invalid response', 500);
            }

            $ids = $json->ayah_ids;
            $user = $this->checkLoginToken();

            $query = Ayah::query()
                ->whereIn('ayahs.id', $ids)
                ->join('surahs', 'ayahs.surah_id', '=', 'surahs.id')
                ->select(
                    'ayahs.*',
                    'surahs.name_en as surah_name_en',
                    'surahs.name_ar as surah_name_ar'
                );

            $totalCount = (int)$query->count('ayahs.id');
            $totalPages = (int)ceil($totalCount / $perPage);

            $query->skip(($page - 1) * $perPage)->take($perPage);

            $ayahs = $this->filterAyahs($validated, $query);

            foreach ($ayahs as $ayah) {
                $filteredTags = $this->getTagsForAyah($ayah, $user);
                $ayah->tags = $filteredTags->map(fn($tag) => ['id' => $tag->id, 'name' => $tag->name]);
                unset($ayah->surah);
            }

            return $this->apiSuccess([
                'meta' => [
                    'total_count' => $totalCount,
                    'total_pages' => $totalPages,
                    'current_page' => $page,
                    'page_size' => $perPage,
                ],
                'result' => $ayahs,
            ], 'Search completed.');
        } catch (Exception $e) {
            return $this->apiError('Failed to perform search: ' . $e->getMessage());
        }
    }

    public function getSurahByAyah(Request $request, int $ayah): JsonResponse
    {
        try {
            $ayahModel = Ayah::query()->findOrFail($ayah);

            $surah = Ayah::query()
                ->where('surah_id', '=', $ayahModel->surah_id)
                ->orderBy('number_in_surah')
                ->select([
                    'ayahs.*',
                    DB::raw('(SELECT s.name_ar FROM surahs s WHERE s.id = ayahs.surah_id LIMIT 1) AS surah_name_ar'),
                    DB::raw('(SELECT s.name_en FROM surahs s WHERE s.id = ayahs.surah_id LIMIT 1) AS surah_name_en'),
                ])
                ->get();

            $user = $this->checkLoginToken();
            foreach ($surah as $surahAyah) {
                $surahAyah->tags = $this->getTagsForAyah($surahAyah, $user);
            }

            return $this->apiSuccess($surah, 'Surah retrieved successfully');
        } catch (Exception $e) {
            return $this->apiError('Failed to retrieve Surah by Ayah: ' . $e->getMessage());
        }
    }

    public function qiraats()
    {
        $qiraats = QiraatReading::all();
        return $this->apiSuccess($qiraats, 'Qiraats retrieved successfully');
    }

    private function withDefaults(array $validated): array
    {
        $validated['page'] = (int)($validated['page'] ?? 1);
        $validated['per_page'] = (int)($validated['per_page'] ?? 20);
        $validated['text_edition'] = (int)($validated['text_edition'] ?? 1);
        $validated['audio_edition'] = (int)($validated['audio_edition'] ?? 110);
        $validated['qiraat_reading_id'] = (int)($validated['qiraat_reading_id'] ?? QiraatImportMaps::baseReadingId());
        $validated['verse'] = (int)($validated['verse'] ?? 0);

        return $validated;
    }

    /**
     * Core query builder + shaping (qiraat + editions + bookmarks + merge parts)
     *
     * - No groupBy
     * - Merge rows into one ayah with mushaf_parts + mushaf_text
     */
    private function filterAyahs(array $validated, Builder $ayahsQuery, bool $mushafAlreadyJoined = false): EloquentCollection
    {
        $textEdition = (int)$validated['text_edition'];
        $audioEdition = (int)$validated['audio_edition'];
        $qiraatReadingId = (int)$validated['qiraat_reading_id'];

        if (!empty($validated['verse']) && (int)$validated['verse'] !== 0) {
            $ayahsQuery->where('ayahs.number_in_surah', (int)$validated['verse']);
        }

        // ✅ BASE MODE: qiraat=1 => NO mushaf joins, NO mushaf merge
        if ($this->usesBaseAyahs($qiraatReadingId)) {
            $this->applyEditionJoins($ayahsQuery, $textEdition, $audioEdition);
            $this->applyBookmarkedSelect($ayahsQuery);

            $ayahsQuery->addSelect([
                DB::raw('ayahs.*'),
                DB::raw('ayahs.ayah_template as base_ayah_template'),
                DB::raw('(SELECT s.name_ar FROM surahs s WHERE s.id = ayahs.surah_id LIMIT 1) AS surah_name_ar'),
                DB::raw('(SELECT s.name_en FROM surahs s WHERE s.id = ayahs.surah_id LIMIT 1) AS surah_name_en'),
                DB::raw("COALESCE(text_ae.data, (SELECT ae1.data FROM ayah_edition ae1 WHERE ae1.ayah_id = 1 AND ae1.edition_id = $textEdition LIMIT 1)) AS translation"),
                DB::raw("COALESCE(audio_ae.data, (SELECT ae2.data FROM ayah_edition ae2 WHERE ae2.ayah_id = 1 AND ae2.edition_id = $audioEdition LIMIT 1)) AS audio"),
            ]);

            $rows = $ayahsQuery->get();

            // shape like mushaf mode expects
            foreach ($rows as $row) {
                $row->template = $row->base_ayah_template;
                unset($row->base_ayah_template);
            }

            return $rows;
        }

        // ✅ MUSHAF MODE: qiraat>1 => do joins + merge
        if (!$mushafAlreadyJoined) {
            $this->applyMushafJoin($ayahsQuery, $qiraatReadingId);
        }

        $this->applyEditionJoins($ayahsQuery, $textEdition, $audioEdition);
        $this->applyBookmarkedSelect($ayahsQuery);

        $ayahsQuery->addSelect([
            DB::raw('ayahs.*'),
            DB::raw('ma.id as mushaf_ayah_id'),
            DB::raw('ma.text as mushaf_text'),
            DB::raw('ma.pure_text as mushaf_pure_text'),
            DB::raw('ma.number_in_surah as mushaf_number_in_surah'),
            DB::raw('ma.page as mushaf_page'),
            DB::raw('ma.juz_id as mushaf_juz_id'),
            DB::raw('ma.hizb_id as mushaf_hizb_id'),
            DB::raw('ma.sajda as mushaf_sajda'),
            DB::raw('map.map_type as mushaf_map_type'),
            DB::raw('map.part_no as mushaf_part_no'),
            DB::raw('map.parts_total as mushaf_parts_total'),
            DB::raw('map.ayah_order as mushaf_ayah_order'),
            DB::raw('ayahs.ayah_template as base_ayah_template'),
            DB::raw('ma.ayah_template as mushaf_ayah_template'),
            DB::raw('(SELECT s.name_ar FROM surahs s WHERE s.id = ayahs.surah_id LIMIT 1) AS surah_name_ar'),
            DB::raw('(SELECT s.name_en FROM surahs s WHERE s.id = ayahs.surah_id LIMIT 1) AS surah_name_en'),
            DB::raw("COALESCE(text_ae.data, (SELECT ae1.data FROM ayah_edition ae1 WHERE ae1.ayah_id = 1 AND ae1.edition_id = $textEdition LIMIT 1)) AS translation"),
            DB::raw("COALESCE(audio_ae.data, (SELECT ae2.data FROM ayah_edition ae2 WHERE ae2.ayah_id = 1 AND ae2.edition_id = $audioEdition LIMIT 1)) AS audio"),
        ]);

        $ayahsQuery
            ->orderByRaw('COALESCE(map.ayah_order, 0) ASC')
            ->orderByRaw('COALESCE(map.part_no, 0) ASC');

        $rows = $ayahsQuery->get();

        return $this->mergeMushafPartsIntoAyahs($rows);
    }

    private function usesBaseAyahs(int $qiraatReadingId): bool
    {
        return QiraatImportMaps::usesBaseAyahs($qiraatReadingId);
    }

    private function applyMushafJoin(Builder $ayahsQuery, int $qiraatReadingId): void
    {
        $ayahsQuery
            ->join('mushaf_ayah_to_ayah_map as map', 'map.ayah_id', '=', 'ayahs.id')
            ->join('mushaf_ayahs as ma', function ($join) use ($qiraatReadingId) {
                $join->on('ma.id', '=', 'map.mushaf_ayah_id')
                    ->where('ma.qiraat_reading_id', '=', $qiraatReadingId);
            });
    }

    private function applyEditionJoins(Builder $ayahsQuery, int $textEdition, int $audioEdition): void
    {
        $ayahsQuery
            ->leftJoin('ayah_edition as text_ae', function ($join) use ($textEdition) {
                $join->on('ayahs.id', '=', 'text_ae.ayah_id')
                    ->where('text_ae.edition_id', '=', $textEdition);
            })
            ->leftJoin('ayah_edition as audio_ae', function ($join) use ($audioEdition) {
                $join->on('ayahs.id', '=', 'audio_ae.ayah_id')
                    ->where('audio_ae.edition_id', '=', $audioEdition);
            });
    }

    private function applyBookmarkedSelect(Builder $ayahsQuery): void
    {
        $user = $this->checkLoginToken();

        if ($user) {
            $ayahsQuery->leftJoin('bookmarks as bookmarks', function ($join) use ($user) {
                $join->on('bookmarks.ayah_id', '=', 'ayahs.id')
                    ->where('bookmarks.user_id', '=', $user->id);
            })
                ->addSelect([DB::raw("CASE WHEN bookmarks.ayah_id IS NOT NULL THEN TRUE ELSE FALSE END AS bookmarked")]);
        } else {
            $ayahsQuery->addSelect([DB::raw('FALSE AS bookmarked')]);
        }
    }

    private function mergeMushafPartsIntoAyahs(EloquentCollection $rows): EloquentCollection
    {
        /** @var array<int, array> $byId */
        $byId = [];

        foreach ($rows as $row) {
            $ayahId = (int)$row->id;

            if (!isset($byId[$ayahId])) {
                $byId[$ayahId] = [
                    'model' => $row,
                    'parts' => []
                ];
            }

            if (!empty($row->mushaf_ayah_id)) {
                $byId[$ayahId]['parts'][] = [
                    'mushaf_ayah_id' => (int)$row->mushaf_ayah_id,
                    'text' => $row->mushaf_text,
                    'pure_text' => $row->mushaf_pure_text,
                    'map_type' => $row->mushaf_map_type,
                    'part_no' => $row->mushaf_part_no !== null ? (int)$row->mushaf_part_no : null,
                    'parts_total' => $row->mushaf_parts_total !== null ? (int)$row->mushaf_parts_total : null,
                    'ayah_order' => $row->mushaf_ayah_order !== null ? (int)$row->mushaf_ayah_order : null,
                    'mushaf_page' => $row->mushaf_page !== null ? (int)$row->mushaf_page : null,
                    'mushaf_juz_id' => $row->mushaf_juz_id !== null ? (int)$row->mushaf_juz_id : null,
                    'mushaf_hizb_id' => $row->mushaf_hizb_id !== null ? (int)$row->mushaf_hizb_id : null,
                    'mushaf_sajda' => $row->mushaf_sajda !== null ? (bool)$row->mushaf_sajda : null,
                ];
            }
        }

        $result = [];
        foreach ($byId as $data) {
            $ayah = $data['model'];
            $parts = $data['parts'];

            // Prefer qiraat mushaf fields for non-base readings; keep base id for tags/bookmarks/translations.
            $mushafTextParts = array_values(array_unique(array_filter(array_map(
                fn(array $part) => $part['text'] ?? null,
                $parts
            ))));
            if (count($mushafTextParts) > 0) {
                $ayah->text = implode(' ', $mushafTextParts);
            }

            $mushafPureTextParts = array_values(array_unique(array_filter(array_map(
                fn(array $part) => $part['pure_text'] ?? null,
                $parts
            ))));
            if (count($mushafPureTextParts) > 0) {
                $ayah->pure_text = implode(' ', $mushafPureTextParts);
            }

            $ayah->number_in_surah = $ayah->mushaf_number_in_surah ?? $ayah->number_in_surah;
            $ayah->page = $ayah->mushaf_page ?? $ayah->page;
            $ayah->juz_id = $ayah->mushaf_juz_id ?? $ayah->juz_id;
            $ayah->hizb_id = $ayah->mushaf_hizb_id ?? $ayah->hizb_id;
            $ayah->sajda = $ayah->mushaf_sajda ?? $ayah->sajda;

            $mushafTemplate = $ayah->mushaf_ayah_template ?: $ayah->base_ayah_template;

            $ayah->template = $mushafTemplate;
            $ayah->ayah_template = $mushafTemplate;

            // Clean up temporary fields
            unset(
                $ayah->base_ayah_template,
                $ayah->mushaf_ayah_template,
                $ayah->mushaf_ayah_id,
                $ayah->mushaf_text,
                $ayah->mushaf_pure_text,
                $ayah->mushaf_number_in_surah,
                $ayah->mushaf_page,
                $ayah->mushaf_juz_id,
                $ayah->mushaf_hizb_id,
                $ayah->mushaf_sajda,
                $ayah->mushaf_map_type,
                $ayah->mushaf_part_no,
                $ayah->mushaf_parts_total,
                $ayah->mushaf_ayah_order
            );

            $result[] = $ayah;
        }

        return new EloquentCollection($result);
    }

    private function getBismillahRow(array $validated): ?Ayah
    {
        return $this->filterAyahs(
            $validated,
            Ayah::query()->where('ayahs.id', 1)
        )->first();
    }

    private function applySurahNames(Ayah $ayah): void
    {
        $surah = Surah::query()
            ->select(['name_ar', 'name_en'])
            ->find($ayah->surah_id);

        $ayah->surah_name_ar = $surah?->name_ar;
        $ayah->surah_name_en = $surah?->name_en;
    }

    private function getTagsForAyahId(?int $ayahId, ?User $user = null): EloquentCollection
    {
        if (!$ayahId) {
            return new EloquentCollection();
        }

        $ayah = Ayah::query()->find($ayahId);

        if (!$ayah) {
            return new EloquentCollection();
        }

        return $this->getTagsForAyah($ayah, $user);
    }

    /**
     * mode:
     * - page: inject bismillah before every ayah that is number_in_surah=1 (except surah 1 & 9)
     * - juz: inject bismillah on surah change (except surah 9)
     */
    private function injectBismillahPerAyahList(EloquentCollection $ayahs, ?Ayah $bismillahRow, ?User $user, string $mode): Collection
    {
        $out = collect();

        if ($ayahs->isEmpty()) {
            return $out;
        }

        if ($mode === 'page') {
            foreach ($ayahs as $ayah) {
                if ((int)$ayah->number_in_surah === 1 && (int)$ayah->surah_id !== 1 && (int)$ayah->surah_id !== 9) {
                    if ($bismillahRow) {
                        $b = clone $bismillahRow;
                        $b->surah_id = (int)$ayah->surah_id;
                        $b->number_in_surah = 0;
                        $this->applySurahNames($b);
                        $b->tags = $this->getTagsForAyah($b, $user);
                        $out->push($b);
                    }
                }

                $ayah->tags = $this->getTagsForAyah($ayah, $user);
                $out->push($ayah);
            }

            return $out;
        }

        // juz mode
        $currentSurah = (int)$ayahs->first()->surah_id;

        foreach ($ayahs as $ayah) {
            if ((int)$ayah->surah_id !== $currentSurah) {
                $currentSurah = (int)$ayah->surah_id;

                if ($currentSurah !== 9 && $bismillahRow) {
                    $b = clone $bismillahRow;
                    $b->surah_id = $currentSurah;
                    $b->number_in_surah = 0;
                    $this->applySurahNames($b);
                    $b->tags = $this->getTagsForAyah($b, $user);
                    $out->push($b);
                }
            }

            $ayah->tags = $this->getTagsForAyah($ayah, $user);
            $out->push($ayah);
        }

        return $out;
    }

    private function getTagsForAyah(Ayah $ayah, ?User $user = null): EloquentCollection
    {
        $tagsQuery = $ayah->tags()->select(
            'tags.id',
            'tags.name',
            'ayah_tags.created_by',
            'ayah_tags.approved_by'
        );

        if ($user && in_array($user->role, ['admin', 'superadmin'])) {
            // admins see all
        } else {
            $tagsQuery->where(function ($query) use ($user) {
                $query->whereExists(function ($subquery) {
                    $subquery->select(DB::raw(1))
                        ->from('users')
                        ->whereColumn('users.id', '=', 'tags.created_by')
                        ->whereIn('users.role', ['admin', 'superadmin']);
                });

                if ($user) {
                    $query->orWhere('ayah_tags.created_by', $user->id);
                }

                $query->orWhereNotNull('ayah_tags.approved_by');
            });
        }

        return $tagsQuery->get()->makeHidden('pivot');
    }
}
