<?php

namespace App\Http\Controllers;

use App\Http\Requests\SurahRequest;
use App\Models\Ayah;
use App\Models\Edition;
use App\Models\User;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Surah;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SurahController extends Controller
{
    public function index(Request $request): JsonResponse
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

            // If 'surah' is provided and not null, return only that Surah
            if (!empty($validated['surah'])) {
                $surah = $query->where('surahs.id', $validated['surah'])->firstOrFail();
                return $this->apiSuccess($surah, 'Surah retrieved successfully');
            }

            // Apply optional filters
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

    public function getByPage(SurahRequest $request, int $page): JsonResponse
    {
        try {
            $validated = $request->validatedWithDefaults();
            $user = $this->checkLoginToken();

            // Get pagination parameters from validated data
            $pageNum = (int)$validated['page']; // renamed to avoid confusion with page parameter
            $perPage = (int)$validated['per_page'];

            // Base query for counting total Ayahs on this page
            $countQuery = Ayah::query()
                ->where('page', $page);

            // Get total count for pagination metadata
            $totalCount = $countQuery->count();

            if ($totalCount === 0) {
                return $this->apiError('Invalid page number or no matching Ayahs', 404);
            }

            // Apply pagination to the query
            $ayahsQuery = Ayah::query()
                ->where('page', $page)
                ->orderBy('surah_id')
                ->orderBy('page')
                ->orderBy('number_in_surah')
                ->skip(($pageNum - 1) * $perPage)
                ->take($perPage);

            // Filter by verse and apply default edition logic
            $ayahs = $this->filterByVerseAndEdition($validated, $ayahsQuery);

            if ($ayahs->isEmpty() && $pageNum == 1) {
                return $this->apiError('Invalid page number or no matching Ayahs', 404);
            }

            // Retrieve the Bismillah row (id = 1)
            $bismillahQuery = Ayah::query()->where('ayahs.id', 1);
            $bismillahRow = $this->filterByVerseAndEdition($validated, $bismillahQuery)->first();

            // Attach tags to each Ayah
            $modifiedAyahs = collect();

            if ($ayahs->isNotEmpty()) {
                foreach ($ayahs as $ayah) {
                    if ($ayah->number_in_surah == 1 && $ayah->surah_id != 1 && $ayah->surah_id != 9) {
                        $bismillahRow->surah_id = $ayah->surah_id;
                        $bismillahRow->number_in_surah = 0;

                        // Fetch and attach tags for Bismillah row
                        $bismillahRow->tags = $this->getTagsForAyah($bismillahRow, $user);
                        $modifiedAyahs->push($bismillahRow);
                    }

                    // Fetch and attach tags for the current Ayah
                    $ayah->tags = $this->getTagsForAyah($ayah, $user);
                    $modifiedAyahs->push($ayah);
                }
            }

            // Calculate total pages
            $totalPages = ceil($totalCount / $perPage);

            return $this->apiSuccess([
                'meta' => [
                    'total_count' => $totalCount,
                    'total_pages' => $totalPages,
                    'current_page' => $pageNum,
                    'per_page' => $perPage
                ],
                'ayahs' => $modifiedAyahs
            ], 'Page retrieved successfully');
        } catch (Exception $e) {
            return $this->apiError('Failed to retrieve page: ' . $e->getMessage());
        }
    }

    public function getByJuz(SurahRequest $request, int $juz): JsonResponse
    {
        try {
            $validated = $request->validatedWithDefaults();
            $user = $this->checkLoginToken();

            // Get pagination parameters from validated data
            $page = (int)$validated['page'];
            $perPage = (int)$validated['per_page'];

            // Base query for Ayahs to get total count
            $countQuery = Ayah::query()
                ->where('juz_id', $juz);

            // Get total count for pagination metadata
            $totalCount = $countQuery->count();

            // If there are no results, return error
            if ($totalCount === 0) {
                return $this->apiError('Invalid Juz number or no matching Ayahs', 404);
            }

            // Apply pagination to the query
            $ayahsQuery = Ayah::query()
                ->where('juz_id', $juz)
                ->orderBy('surah_id')
                ->orderBy('juz_id')
                ->orderBy('number_in_surah')
                ->skip(($page - 1) * $perPage)
                ->take($perPage);

            // Filter by verse and apply edition logic
            $ayahs = $this->filterByVerseAndEdition($validated, $ayahsQuery);

            if ($ayahs->isEmpty() && $page == 1) {
                return $this->apiError('Invalid Juz number or no matching Ayahs', 404);
            }

            // Retrieve the Bismillah row (id = 1)
            $bismillahQuery = Ayah::query()->where('ayahs.id', 1);
            $bismillahRow = $this->filterByVerseAndEdition($validated, $bismillahQuery)->first();

            // Process the ayahs collection to insert Bismillah before the first ayah of each new surah (except surah 9)
            $modifiedAyahs = collect();

            // Only set currentSurah if we have ayahs
            if ($ayahs->isNotEmpty()) {
                $currentSurah = $ayahs[0]->surah_id;

                foreach ($ayahs as $ayah) {
                    if ($ayah->surah_id !== $currentSurah) {
                        $currentSurah = $ayah->surah_id;
                        if ($currentSurah != 9) {
                            $bismillahRow->surah_id = $currentSurah;
                            $bismillahRow->number_in_surah = 0;

                            // Fetch and attach tags for Bismillah row
                            $bismillahRow->tags = $this->getTagsForAyah($bismillahRow, $user);
                            $modifiedAyahs->push($bismillahRow);
                        }
                    }

                    // Fetch and attach tags for the current Ayah
                    $ayah->tags = $this->getTagsForAyah($ayah, $user);
                    $modifiedAyahs->push($ayah);
                }
            }

            // Calculate total pages
            $totalPages = ceil($totalCount / $perPage);

            return $this->apiSuccess([
                'meta' => [
                    'total_count' => $totalCount,
                    'total_pages' => $totalPages,
                    'current_page' => $page,
                    'per_page' => $perPage
                ],
                'ayahs' => $modifiedAyahs
            ], 'Juz retrieved successfully');
        } catch (Exception $e) {
            return $this->apiError("Failed to retrieve Juz: " . $e->getMessage());
        }
    }

    public function getBySurah(SurahRequest $request, int $surah): JsonResponse
    {
        try {
            $validated = $request->validatedWithDefaults();
            $user = $this->checkLoginToken();

            // Get pagination parameters from validated data
            $page = (int)$validated['page'];
            $perPage = (int)$validated['per_page'];

            // 1. Base query
            $ayahsQuery = Ayah::query()
                ->where('ayahs.surah_id', $surah)
                ->orderBy('ayahs.juz_id')
                ->orderBy('ayahs.page')
                ->orderBy('ayahs.number_in_surah');

            // Get total count for pagination metadata
            $totalCount = $ayahsQuery->count();

            // 2. Apply filters and get paginated results
            // Note: We need to apply pagination before getting the results
            $ayahsQuery = Ayah::query()
                ->where('ayahs.surah_id', $surah)
                ->orderBy('ayahs.juz_id')
                ->orderBy('ayahs.page')
                ->orderBy('ayahs.number_in_surah')
                ->skip(($page - 1) * $perPage)
                ->take($perPage);

            $ayahs = $this->filterByVerseAndEdition($validated, $ayahsQuery);

            if ($ayahs->isEmpty() && $page == 1) {
                return $this->apiError('Invalid Surah number or no matching Ayahs', 404);
            }

            // 3. Fetch and prepare Bismillah if needed (only for first page)
            $modifiedAyahs = collect();

            if ($page == 1 && $surah !== 1 && $surah !== 9) {
                $bismillahQuery = Ayah::query()->where('ayahs.id', 1);
                $bismillahRow = $this->filterByVerseAndEdition($validated, $bismillahQuery)->first();

                if ($bismillahRow) {
                    $bismillahRow->surah_id = $surah;
                    $bismillahRow->number_in_surah = 0;

                    // Attach tags to Bismillah based on user role
                    $bismillahRow->tags = $this->getTagsForAyah($bismillahRow, $user);
                    $modifiedAyahs->push($bismillahRow);
                }
            }

            // 4. Attach tags to each Ayah and add to final list
            foreach ($ayahs as $ayah) {
                $ayah->tags = $this->getTagsForAyah($ayah, $user);
                $modifiedAyahs->push($ayah);
            }

            // Calculate total pages
            $totalPages = ceil($totalCount / $perPage);

            return $this->apiSuccess([
                'meta' => [
                    'total_count' => $totalCount,
                    'total_pages' => $totalPages,
                    'current_page' => $page,
                    'per_page' => $perPage
                ],
                'ayahs' => $modifiedAyahs
            ], 'Surah retrieved successfully');
        } catch (Exception $e) {
            return $this->apiError("Failed to retrieve Surah: " . $e->getMessage());
        }
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string',
            'type' => 'required|string|in:exact,semantic',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'text_edition' => 'sometimes|integer|exists:editions,id',
            'audio_edition' => 'sometimes|integer|exists:editions,id',
        ]);

        $page = (int)($validated['page'] ?? 1);
        $perPage = (int)($validated['per_page'] ?? 20);

        // call out to your AI service
        $result = Http::post(
            env('AI_URL') . "/{$validated['type']}_search",
            ['query' => $validated['q']]
        );

        $result = $result->body();
        $result = json_decode($result);
        $ids = $result->ayah_ids;
        $user = $this->checkLoginToken();

        // Base query for ayahs
        $query = Ayah::query()->whereIn('ayahs.id', $ids)
            ->join('surahs', 'ayahs.surah_id', '=', 'surahs.id')
            ->select(
                'ayahs.*',
                'surahs.name_en as surah_name_en',
                'surahs.name_ar as surah_name_ar'
            );

        // Count total results before applying pagination
        $totalCount = $query->count('ayahs.id');
        $totalPages = (int)ceil($totalCount / $perPage);

        // Apply pagination to the query
        $query->skip(($page - 1) * $perPage)
            ->take($perPage);

        // Use filterByVerseAndEdition to add translations and audio
        $ayahs = $this->filterByVerseAndEdition($validated, $query);

        // Load tags with proper filtering for each ayah
        foreach ($ayahs as $ayah) {
            // Get filtered tags based on user role/permissions
            $filteredTags = $this->getTagsForAyah($ayah, $user);

            // Replace the tags property with our filtered tags
            $ayah->tags = $filteredTags->map(function ($tag) {
                return [
                    'id' => $tag->id,
                    'name' => $tag->name
                ];
            });

            // Remove the surah relationship if it was loaded
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
    }

    /**
     * Get the appropriate tags for an ayah based on user role
     *
     * @param Ayah $ayah
     * @param User|null $user
     * @return Collection
     */
    private function getTagsForAyah(Ayah $ayah, User $user = null): Collection
    {
        $tagsQuery = $ayah->tags()->select(
            'tags.id',
            'tags.name',
            'ayah_tags.created_by',
            'ayah_tags.approved_by'
        );

        // If user is admin or superadmin, they can see all tags
        if ($user && in_array($user->role, ['admin', 'superadmin'])) {
            // No additional filters - admins see everything
        }
        // Regular users see:
        // 1. Their own tags (approved or not)
        // 2. Admin/superadmin created tags (whether approved or not)
        // 3. Approved tags (by any user)
        else {
            $tagsQuery->where(function ($query) use ($user) {
                // Admin/superadmin created tags (all of them)
                $query->whereExists(function ($subquery) {
                    $subquery->select(DB::raw(1))
                        ->from('users')
                        ->whereColumn('users.id', '=', 'tags.created_by')
                        ->whereIn('users.role', ['admin', 'superadmin']);
                });

                // If user is logged in, also include their own tags
                if ($user) {
                    $query->orWhere('ayah_tags.created_by', $user->id);
                }

                // Tags approved by anyone
                $query->orWhereNotNull('ayah_tags.approved_by');
            });
        }

        return $tagsQuery->get()->makeHidden('pivot');
    }


    // ayah-surah/1 (for bookmarks)
    public function getSurahByAyah(Request $request, int $ayah): JsonResponse
    {
        try {
            $ayah = Ayah::query()->findOrFail($ayah);

            $surah = Ayah::query()
                ->where('surah_id', '=', $ayah->surah_id)
                ->orderBy('number_in_surah')
                ->get();

            return $this->apiSuccess($surah, 'Surah retrieved successfully');
        } catch (Exception $e) {
            return $this->apiError('Failed to retrieve Surah by Ayah');
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

    private function filterByVerseAndEdition(array $validated, Builder $ayahsQuery): Collection
    {
        // Ensure default editions are applied
        $textEdition = $validated['text_edition'] ?? 1;
        $audioEdition = $validated['audio_edition'] ?? 110;

        // If a specific verse is provided, apply the filter
        if (!empty($validated['verse']) && $validated['verse'] !== 0) {
            $ayahsQuery->where('number_in_surah', $validated['verse']);
        }

        // Join ayah_edition to fetch translations and audio
        $ayahsQuery
            ->leftJoin('ayah_edition as text_ae', function ($join) use ($textEdition) {
                $join->on('ayahs.id', '=', 'text_ae.ayah_id')
                    ->where('text_ae.edition_id', '=', $textEdition);
            })
            ->leftJoin('ayah_edition as audio_ae', function ($join) use ($audioEdition) {
                $join->on('ayahs.id', '=', 'audio_ae.ayah_id')
                    ->where('audio_ae.edition_id', '=', $audioEdition);
            })
            ->leftJoin('editions as text_ed', 'text_ed.id', '=', 'text_ae.edition_id')
            ->leftJoin('editions as audio_ed', 'audio_ed.id', '=', 'audio_ae.edition_id')
            ->select([
                'ayahs.*',
                DB::raw("COALESCE(text_ae.data, (SELECT ae1.data FROM ayah_edition ae1 WHERE ae1.ayah_id = 1 AND ae1.edition_id = $textEdition LIMIT 1)) AS translation"),
                DB::raw("COALESCE(audio_ae.data, (SELECT ae2.data FROM ayah_edition ae2 WHERE ae2.ayah_id = 1 AND ae2.edition_id = $audioEdition LIMIT 1)) AS audio")
            ]);

        // Manually check for authenticated user
        $user = $this->checkLoginToken();

        // If a user is authenticated, add a 'bookmarked' field
        if ($user) {
            $ayahsQuery->leftJoin('bookmarks as bookmarks', 'bookmarks.ayah_id', '=', 'ayahs.id')
                ->addSelect([
                    DB::raw("CASE WHEN bookmarks.ayah_id IS NOT NULL THEN TRUE ELSE FALSE END AS bookmarked")
                ]);
        } else {
            // If no authenticated user, just add a 'bookmarked' field with false (to prevent errors)
            $ayahsQuery->addSelect([DB::raw('FALSE AS bookmarked')]);
        }

        return $ayahsQuery->get();
    }
}
