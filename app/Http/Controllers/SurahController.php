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

            $query = Surah::query()->select('id', 'number', 'name_ar', 'name_en', 'name_en_translation', 'type');

            // If 'surah' is provided, return only that Surah
            if (!empty($validated['surah'])) {
                $surah = $query->where('id', $validated['surah'])->firstOrFail();

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

            $surahs = $query->orderBy('id')->get();

            return $this->apiSuccess($surahs, 'Surahs retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiError('Failed to retrieve Surahs');
        }
    }

    public function getByPage(Request $request, int $page)
    {
        try {
            $validated = $request->validate([
                'verse' => 'sometimes|int|min:1',
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

            return $this->apiSuccess($ayahs, 'Page retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiError('Failed to retrieve page', $e->getMessage());
        }
    }


    public function getByJuz(Request $request, int $juz)
    {
        try {
            $validated = $request->validate([
                'verse' => 'sometimes|int|min:1',
                'edition' => 'sometimes|integer|min:1|exists:editions,id|nullable'
            ]);

            // Base query for Ayahs
            $ayahsQuery = Ayah::query()
                ->where('juz_id', $juz)
                ->orderBy('surah_id')
                ->orderBy('juz_id')
                ->orderBy('number_in_surah');

            // Filter by verse if provided
            $ayahs = $this->filterByVerseAndEdition($validated, $ayahsQuery); // Execute query

            if ($ayahs->isEmpty()) {
                return $this->apiError('Invalid Juz number or no matching Ayahs', 404);
            }

            return $this->apiSuccess($ayahs, 'Juz retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiError('Failed to retrieve Juz');
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

            // Base query for Ayahs
            $ayahsQuery = Ayah::query()
                ->where('surah_id', $surah)
                ->orderBy('juz_id')
                ->orderBy('page')
                ->orderBy('number_in_surah');

            // Filter by verse if provided
            $ayahs = $this->filterByVerseAndEdition($validated, $ayahsQuery); // Execute query

            if ($ayahs->isEmpty()) {
                return $this->apiError('Invalid Surah number or no matching Ayahs', 404);
            }

            return $this->apiSuccess($ayahs, 'Surah retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiError('Failed to retrieve Surah');
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
