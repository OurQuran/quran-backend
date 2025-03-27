<?php

namespace App\Http\Controllers;

use App\Http\Requests\SurahRequest;
use App\Models\Ayah;
use App\Models\Edition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use App\Models\Surah;
use Illuminate\Support\Facades\DB;

class SurahController extends Controller
{
    public function index(Request $request)
    {
        try {
            $validated = $request->validate([
                'surah' => 'sometimes|int',
                'type' => 'sometimes|in:Meccan,Medinan',
                'revelation_order' => 'sometimes|in:asc,desc',
                'name' => 'sometimes|string',
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

            // If 'surah' is provided, return only that Surah
            if (!empty($validated['surah'])) {
                $surah = $query->where('surahs.id', $validated['surah'])->firstOrFail();

                return $this->apiSuccess($surah, 'Surah retrieved successfully');
            }

            // Apply optional filters
            if (!empty($validated['type'])) {
                $query->where('surahs.type', $validated['type']);
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
        } catch (\Exception $e) {
            return $this->apiError("Failed to retrieve Surahs: " . $e->getMessage());
        }
    }

    public function getByPage(SurahRequest $request, int $page)
    {
        try {
            $validated = $request->validatedWithDefaults();

            // Base query for Ayahs
            $ayahsQuery = Ayah::query()
                ->where('page', $page)
                ->orderBy('surah_id')
                ->orderBy('page')
                ->orderBy('number_in_surah');

            // Filter by verse and apply default edition logic
            $ayahs = $this->filterByVerseAndEdition($validated, $ayahsQuery);

            if ($ayahs->isEmpty()) {
                return $this->apiError('Invalid page number or no matching Ayahs', 404);
            }

            // Retrieve the Bismillah row (id = 1)
            $bismillahQuery = Ayah::query()->where('ayahs.id', 1);
            $bismillahRow = $this->filterByVerseAndEdition($validated, $bismillahQuery)->first();

            // Attach tags to each Ayah
            $modifiedAyahs = collect();
            foreach ($ayahs as $ayah) {
                if ($ayah->number_in_surah == 1 && $ayah->surah_id != 1 && $ayah->surah_id != 9) {
                    $bismillahRow->surah_id = $ayah->surah_id;
                    $bismillahRow->number_in_surah = 1;

                    // Fetch and attach tags for Bismillah row
                    $bismillahRow->tags = $bismillahRow->tags()->select('tags.id', 'tags.name')->get();
                    $modifiedAyahs->push($bismillahRow);
                }

                // Fetch and attach tags for the current Ayah
                $ayah->tags = $ayah->tags()->select('tags.id', 'tags.name')->get()->makeHidden('pivot');
                $modifiedAyahs->push($ayah);
            }

            return $this->apiSuccess($modifiedAyahs, 'Page retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiError('Failed to retrieve page', $e->getMessage());
        }
    }

    public function getByJuz(SurahRequest $request, int $juz)
    {
        try {
            $validated = $request->validatedWithDefaults();

            // Base query for Ayahs
            $ayahsQuery = Ayah::query()
                ->where('juz_id', $juz)
                ->orderBy('surah_id')
                ->orderBy('juz_id')
                ->orderBy('number_in_surah');

            // Filter by verse and apply edition logic
            $ayahs = $this->filterByVerseAndEdition($validated, $ayahsQuery);

            if ($ayahs->isEmpty()) {
                return $this->apiError('Invalid Juz number or no matching Ayahs', 404);
            }

            // Retrieve the Bismillah row (id = 1)
            $bismillahQuery = Ayah::query()->where('ayahs.id', 1);
            $bismillahRow = $this->filterByVerseAndEdition($validated, $bismillahQuery)->first();

            // Process the ayahs collection to insert Bismillah before the first ayah of each new surah (except surah 9)
            $modifiedAyahs = collect();
            $currentSurah = $ayahs[0]->surah_id;

            foreach ($ayahs as $ayah) {
                if ($ayah->surah_id !== $currentSurah) {
                    $currentSurah = $ayah->surah_id;
                    if ($currentSurah != 9) {
                        $bismillahRow->surah_id = $currentSurah;
                        $bismillahRow->number_in_surah = 1;

                        // Fetch and attach tags for Bismillah row (Hides pivot)
                        $bismillahRow->tags = $bismillahRow->tags()->select('tags.id', 'tags.name')->get()->makeHidden('pivot');
                        $modifiedAyahs->push($bismillahRow);
                    }
                }

                // Fetch and attach tags for the current Ayah (Hides pivot)
                $ayah->tags = $ayah->tags()->select('tags.id', 'tags.name')->get()->makeHidden('pivot');
                $modifiedAyahs->push($ayah);
            }

            return $this->apiSuccess($modifiedAyahs, 'Juz retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiError("Failed to retrieve Juz", $e->getMessage());
        }
    }

    public function getBySurah(SurahRequest $request, int $surah)
    {
        try {
            $validated = $request->validatedWithDefaults();

            $ayahsQuery = Ayah::query();

            if ($surah !== 9) {
                $ayahsQuery
                    ->where('ayahs.surah_id', $surah)
                    ->orWhere(function ($query) {
                        $query->where('ayahs.id', 1); // Explicitly reference ayahs.id
                    })
                    ->orderBy('ayahs.juz_id')
                    ->orderBy('ayahs.page')
                    ->orderBy('ayahs.number_in_surah');
            } else {
                $ayahsQuery
                    ->where('surah_id', $surah)
                    ->orderBy('juz_id')
                    ->orderBy('page')
                    ->orderBy('number_in_surah');
            }

            // Filter by verse and apply edition logic
            $ayahs = $this->filterByVerseAndEdition($validated, $ayahsQuery);

            if ($ayahs->isEmpty()) {
                return $this->apiError('Invalid Surah number or no matching Ayahs', 404);
            }

            // Remove the pivot field from tags
            foreach ($ayahs as $ayah) {
                $ayah->tags = $ayah->tags()->select('tags.id', 'tags.name')->get()->makeHidden('pivot');
            }

            return $this->apiSuccess($ayahs, 'Surah retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiError("Failed to retrieve Surah", $e->getMessage());
        }
    }

    // ayah-surah/1 (for bookmarks)
    public function getSurahByAyah(Request $request, int $ayah){
        try {
            $ayah = Ayah::query()->findOrFail($ayah);

            $surah = Ayah::query()
                ->where('surah_id', '=', $ayah->surah_id)
                ->orderBy('number_in_surah')
                ->get();

            return $this->apiSuccess($surah, 'Surah retrieved successfully');
        } catch (\Exception $e){
            return $this->apiError('Failed to retrieve Surah by Ayah');
        }
    }

    public function editions(){
        try {
            $editions = Edition::query()->get();

            return $this->apiSuccess($editions, 'Editions retrieved successfully');
        } catch (\Exception $e){
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
