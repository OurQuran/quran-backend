<?php

namespace App\Http\Controllers;

use App\Models\Bookmark;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class BookmarksController extends Controller
{
    public function index()
    {
        try {
            $bookmarks = Bookmark::query()
                ->where("user_id", "=", Auth::id())
                ->with(['ayah'])
                ->get();

            return $this->apiSuccess($bookmarks, 'Bookmarks returned successfully');
        } catch (\Exception $e) {
            return $this->apiError('Failed to retrieve Bookmarks');
        }
    }

    public function show(int $id){
        try {
            $bookmark = Bookmark::query()->with(['ayah'])->findOrFail($id);

            if($bookmark->user_id !== Auth::id()){
                return $this->apiError('Bookmark not found', 404);
            }

            return $this->apiSuccess($bookmark, 'Bookmark returned successfully');
        } catch (\Exception $e) {
            return $this->apiError('Failed to retrieve Bookmark');
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

            return $this->apiSuccess($bookmark, 'Bookmark created successfully.');
        } catch (\Exception $e) {
            return $this->apiError('Failed to create Bookmark');
        }
    }


    public function destroy(int $id)
    {
        try {
            $bookmark = Bookmark::query()
                ->with('ayahs')
                ->where('id', $id)
                ->delete();

            return $this->apiSuccess(null, 'Bookmark deleted successfully');
        } catch (\Exception $e) {
            return $this->apiError('Failed to delete Bookmark');
        }
    }
}
