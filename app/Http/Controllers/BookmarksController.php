<?php

namespace App\Http\Controllers;

use App\Models\Bookmark;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class BookmarksController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $page = (int)($validated['page'] ?? 1);
        $perPage = (int)($validated['per_page'] ?? 20);

        try {
            // Fetch all necessary bookmarks without grouping by surah_id
            $bookmarksQuery = Bookmark::query()
                ->where("user_id", "=", Auth::id())
                ->join('ayahs', 'ayahs.id', '=', 'bookmarks.ayah_id')
                ->join('surahs', 'ayahs.surah_id', '=', 'surahs.id')
                ->select(
                    'surahs.id as surah_id',
                    'surahs.name_ar as name_ar',
                    'surahs.name_en as name_en',
                    'ayahs.id as ayah_id', // Alias to avoid ambiguity
                    'ayahs.text as ayah_text',
                    'ayahs.number_in_surah as number_in_surah'
                )
                ->orderBy('ayah_id');

            $totalCount = $bookmarksQuery->count();
            $totalPages = ceil($totalCount / $perPage);

            $bookmarks = $bookmarksQuery->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            $groupedBookmarks = $bookmarks->groupBy('surah_id');

            // Prepare final response
            $formattedBookmarks = [];
            foreach ($groupedBookmarks as $surahId => $bookmarksGroup) {
                // Get the surah's name from the first item in the group
                $surahName = $bookmarksGroup->first()->name_en; // Or name_ar if you prefer
                $formattedBookmarks[$surahName] = $bookmarksGroup->map(function ($item) {
                    return [
                        'surah_id' => $item->surah_id,
                        'ayah_id' => $item->ayah_id,
                        'ayah_text' => $item->ayah_text,
                        'number_in_surah' => $item->number_in_surah,
                    ];
                });
            }

            return $this->apiSuccess([
                'meta' => [
                    'total_count' => $totalCount,
                    'total_pages' => $totalPages,
                    'current_page' => $page,
                    'page_size' => $perPage
                ],
                'result' => $formattedBookmarks
            ], 'Bookmarks retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiError("Failed to retrieve Bookmarks: $e");
        }
    }

    public function create(Request $request)
    {
        try {
            $validated = $request->validate([
                'ayah_id' => 'required|int|exists:ayahs,id'
            ]);

            $userId = Auth::id();
            $ayahId = $validated['ayah_id'];

            $existingBookmark = Bookmark::where('user_id', $userId)
                ->where('ayah_id', $ayahId)
                ->first();

            if ($existingBookmark) {
                return $this->apiError('Bookmark already exists.', 409);
            }

            $bookmark = Bookmark::create([
                'ayah_id' => $ayahId,
                'user_id' => $userId
            ]);

            return $this->apiSuccess($bookmark, 'Bookmark created successfully.', 201);
        } catch (\Exception $e) {
            return $this->apiError('Failed to create Bookmark');
        }
    }


    public function destroy(int $id)
    {
        try {
            $bookmark = Bookmark::query()
                ->where('user_id', "=", Auth::id())
                ->findOrFail($id);

            $bookmark->delete();

            return $this->apiSuccess(null, 'Bookmark deleted successfully');
        } catch (\Exception $e) {
            return $this->apiError('Failed to delete Bookmark');
        }
    }
}
