<?php

namespace App\Http\Controllers;

use App\Models\Ayah;
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
                    'surahs.name_en as surah_name_en',
                    'surahs.name_ar as surah_name_ar',
                    'ayahs.id as ayah_id',
                    'ayahs.text as ayah_text',
                    'ayahs.number_in_surah as number_in_surah',
                    'ayahs.page',
                    'ayahs.juz_id',
                    'ayahs.hizb_id',
                    'ayahs.sajda',
                    'ayahs.ayah_template',
                    'ayahs.pure_text',
                )
                ->orderBy('created_at', 'desc');

            $totalCount = $bookmarksQuery->count();
            $totalPages = ceil($totalCount / $perPage);

            $bookmarks = $bookmarksQuery->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            // Get the current authenticated user
            $user = Auth::user();

            // Prepare final response as a flat array
            $formattedBookmarks = $bookmarks->map(function ($item) use ($user) {
                // Get tags for this ayah
                $ayah = Ayah::find($item->ayah_id);
                $tags = $this->getTagsForAyah($ayah, $user);

                return [
                    'id' => $item->ayah_id,
                    'surah_id' => $item->surah_id,
                    'surah_name_ar' => $item->surah_name_ar,
                    'surah_name_en' => $item->surah_name_en,
                    'ayah_text' => $item->ayah_text,
                    'number_in_surah' => $item->number_in_surah,
                    'page' => $item->page,
                    'juz_id' => $item->juz_id,
                    'hizb_id' => $item->hizb_id,
                    'sajda' => $item->sajda,
                    'ayah_template' => $item->ayah_template,
                    'pure_text' => $item->pure_text,
                    'bookmarked' => true,
                    'tags' => $tags->map(function ($tag) {
                        return [
                            'id' => $tag->id,
                            'name' => $tag->name
                        ];
                    })
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
            return $this->apiError('Failed to retrieve Bookmarks: ' . $e->getMessage());
        }
    }

    /**
     * Get tags for a specific ayah based on user permissions
     *
     * @param \App\Models\Ayah $ayah
     * @param \App\Models\User|null $user
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getTagsForAyah($ayah, $user = null)
    {
        $tagsQuery = $ayah->tags()->select(
            'tags.id',
            'tags.name'
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
            $tagsQuery->where(function($query) use ($user) {
                // Admin/superadmin created tags (all of them)
                $query->whereExists(function($subquery) {
                    $subquery->select(\DB::raw(1))
                        ->from('users')
                        ->whereColumn('users.id', '=', 'ayah_tags.created_by')
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

        return $tagsQuery->get();
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

    public function destroy(Ayah $ayah)
    {
        Bookmark::query()
            ->where('ayah_id', $ayah->id)
            ->where('user_id', Auth::id())
            ->firstOrFail()
            ->delete();

        return $this->apiSuccess(null, 'Bookmark deleted successfully');
    }

}
