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
                    'bookmarks.id as bookmark_id',
                    'surahs.id as surah_id',
                    'surahs.name_en as surah_name', // Include surah_name directly
                    'ayahs.id as ayah_id', // Alias to avoid ambiguity
                    'ayahs.text as ayah_text',
                    'ayahs.number_in_surah as number_in_surah',
                    'bookmarks.created_at as created_at',
                )
                ->orderBy('ayah_id');

            $totalCount = $bookmarksQuery->count();
            $totalPages = ceil($totalCount / $perPage);

            $bookmarks = $bookmarksQuery->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            // Prepare final response as a flat array
            $formattedBookmarks = $bookmarks->map(function ($item) {
                return [
                    'bookmark_id' => $item->bookmark_id,
                    'surah_id' => $item->surah_id,
                    'surah_name' => $item->surah_name, // Flattened field for surah_name
                    'ayah_id' => $item->ayah_id,
                    'ayah_text' => $item->ayah_text,
                    'number_in_surah' => $item->number_in_surah,
                    'created_at' => $item->created_at,
                ];
            });

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
            return $this->apiError('Failed to retrieve Bookmarks');
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
                return $this->apiError('Bookmark already exists', 409);
            }

            $bookmark = Bookmark::create([
                'ayah_id' => $ayahId,
                'user_id' => $userId,
                'created_at' => now(),
            ]);

            return $this->apiSuccess($bookmark, 'Bookmark created successfully', 201);
        } catch (\Exception $e) {
            return $this->apiError('Failed to create Bookmark');
        }
    }

    public function destroy(Bookmark $bookmark)
    {
        if ($bookmark->user_id !== Auth::id()) {
            return $this->apiError('Unauthorized', 403);
        }

        $bookmark->delete();

        return $this->apiSuccess(null, 'Bookmark deleted successfully');
    }

}
