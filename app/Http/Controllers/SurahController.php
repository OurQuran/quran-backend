<?php

namespace App\Http\Controllers;

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
                ->join('ayahs', 'surahs.id', '=', 'ayahs.surah_id')
                ->select(
                    'surahs.id',
                    'surahs.number', // Explicitly reference surahs.number
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
                $query->where('type', $validated['type']);
            }
            if (!empty($validated['revelation_order'])) {
                $query->orderBy('number', $validated['revelation_order']);
            }
            if (!empty($validated['name'])) {
                $query->where('name_en', 'ILIKE', "%{$validated['name']}%")
                    ->orWhere('name_ar', 'ILIKE', "%{$validated['name']}%")
                    ->orWhere('name_en_translation', 'ILIKE', "%{$validated['name']}%");
            }

            $surahs = $query->orderBy('surahs.id')->get();

            return $this->apiSuccess($surahs, 'Surahs retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiError("Failed to retrieve Surahs $e");
        }
    }

    public function getByPage(Request $request, int $page)
    {
        try {
            $validated = $request->validate([
                'verse' => 'sometimes|integer|min:1',
                'edition' => 'sometimes|integer|min:1|exists:editions,id|nullable'
            ]);

            // Base query for Ayahs
            $ayahsQuery = Ayah::query()
                ->where('page', $page)
                ->orderBy('surah_id')
                ->orderBy('page')
                ->orderBy('number_in_surah');

            // Filter by verse if provided
            $ayahs = $this->filterByVerseAndEdition($validated, $ayahsQuery); // Execute query

            if ($ayahs->isEmpty()) {
                return $this->apiError('Invalid page number or no matching Ayahs', 404);
            }

            // Retrieve the bismillah row (with id = 1)
            $bismillahRow = Ayah::find(1);
            if (!$bismillahRow) {
                return $this->apiError('Bismillah row not found', 404);
            }

            // Process the ayahs collection: before each ayah with number_in_surah == 1 (and surah_id != 9),
            // insert a cloned bismillah row.
            $modifiedAyahs = collect();
            foreach ($ayahs as $ayah) {
                if ($ayah->number_in_surah == 1 && $ayah->surah_id != 1 && $ayah->surah_id != 9) {
                    $bismillahRow->surah_id = $ayah->surah_id;
                    $bismillahRow->number_in_surah = 1;

                    $modifiedAyahs->push($bismillahRow);
                }
                $modifiedAyahs->push($ayah);
            }

            return $this->apiSuccess($modifiedAyahs, 'Page retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiError('Failed to retrieve page', $e->getMessage());
        }
    }


    public function getByJuz(Request $request, int $juz)
    {
        try {
            $validated = $request->validate([
                'verse' => 'sometimes|integer|min:1',
                'edition' => 'sometimes|integer|min:1|exists:editions,id|nullable'
            ]);

            // Base query for Ayahs
            $ayahsQuery = Ayah::query()
                ->where('juz_id', $juz)
                ->orderBy('surah_id')
                ->orderBy('juz_id')
                ->orderBy('number_in_surah');

            // Filter by verse if provided
            $ayahs = $this->filterByVerseAndEdition($validated, $ayahsQuery);

            if ($ayahs->isEmpty()) {
                return $this->apiError('Invalid Juz number or no matching Ayahs', 404);
            }

            // Retrieve the bismillah row (with id = 1)
            $bismillahRow = Ayah::query()->where('ayahs.id',1);
            $bismillahRow = $this->filterByVerseAndEdition($validated, $bismillahRow)->first();

            // Process the ayahs collection to insert bismillah before the first ayah of each new surah (except surah 9)
            $modifiedAyahs = collect();
            $currentSurah = $ayahs[0]->surah_id;
            foreach ($ayahs as $ayah) {
                if ($ayah->surah_id !== $currentSurah) {
                    $currentSurah = $ayah->surah_id;
                    if ($currentSurah != 9) {
                        $bismillahRow->surah_id = $currentSurah;
                        $bismillahRow->number_in_surah = 1;

                        $modifiedAyahs->push($bismillahRow);
                    }
                }
                $modifiedAyahs->push($ayah);
            }

            return $this->apiSuccess($modifiedAyahs, 'Juz retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiError("Failed to retrieve Juz $e");
        }
    }

    // ?verse
    // ?edition
    public function getBySurah(Request $request, int $surah)
    {
        try {
            $validated = $request->validate([
                'verse' => 'sometimes|int|min:1',
                'edition' => 'sometimes|integer|min:1|exists:editions,id|nullable'
            ]);

            $ayahsQuery = Ayah::query();

            if ($surah !== 9){
                $ayahsQuery
                    ->where('ayahs.surah_id', $surah)
                    ->orWhere(function ($query) {
                        $query->where('ayahs.id', 1);  // Explicitly reference ayahs.id
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


            // Filter by verse if provided
            $ayahs = $this->filterByVerseAndEdition($validated, $ayahsQuery); // Execute query

            if ($ayahs->isEmpty()) {
                return $this->apiError('Invalid Surah number or no matching Ayahs', 404);
            }

            return $this->apiSuccess($ayahs, 'Surah retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiError("Failed to retrieve Surah $e");
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
        // If a specific verse is provided, apply the filter
        if (!empty($validated['verse']) && $validated['verse'] !== 0) {
            $ayahsQuery->where('number_in_surah', $validated['verse']);
        }

        // If an edition is provided, join ayah_edition to get translations
        if (!empty($validated['edition']) && $validated['edition'] !== 0) {
            $editionId = $validated['edition'];

            $ayahsQuery->leftJoin('ayah_edition as ae', function ($join) use ($editionId) {
                $join->on('ayahs.id', '=', 'ae.ayah_id')
                    ->where('ae.edition_id', '=', $editionId);
            })
                ->leftJoin('editions as e', 'e.id', '=', 'ae.edition_id')
                ->select([
                    'ayahs.*', // Select all columns from ayahs table
                    DB::raw("CASE
                WHEN ayahs.number_in_surah = 0 THEN
                    (SELECT ae1.data FROM ayah_edition ae1
                     WHERE ae1.ayah_id = 1 AND ae1.edition_id = $editionId
                     LIMIT 1)
                ELSE ae.data
            END AS translation") // Select translation based on edition
                ]);
        } else {
            $ayahsQuery->select('ayahs.*'); // Default selection when no edition is provided
        }

        // Manually check for authenticated user
        $user = $this->checkLoginToken();

        // If a user is authenticated, add a 'bookmarked' field
        if ($user) {
            $ayahsQuery->leftJoin('bookmarks as bookmarks', 'bookmarks.ayah_id', '=', 'ayahs.id')
                // ->where('bookmarks.user_id', '=', $user->id) // Filter by authenticated user
                ->addSelect([
                    DB::raw("CASE WHEN bookmarks.ayah_id IS NOT NULL THEN TRUE ELSE FALSE END AS bookmarked") // Compute 'bookmarked' field
                ]);
        } else {
            // If no authenticated user, just add a 'bookmarked' field with false (to prevent errors)
            $ayahsQuery->addSelect([DB::raw('FALSE AS bookmarked')]);
        }

        // Execute the query and return the result as a collection
        return $ayahsQuery->get();
    }
}
