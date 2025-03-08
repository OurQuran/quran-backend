<?php

namespace App\Http\Controllers;

use App\Models\Ayah;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TagController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'include_users' => 'sometimes|boolean',
        ]);

        $query = Tag::query();

        // If 'name' is provided, get the tag and its children recursively
        if (!empty($validated['name'])) {
            $query->whereRaw('name ILIKE ?', ["%{$validated['name']}%"])->with('allChildren');
        }

        // Conditionally include creator and updater if requested
        if (!empty($validated['include_users'])) {
            $query->with(['creator:id,name', 'updater:id,name']);
        }

        // Sort tags (parent_id first for better hierarchical structure)
        $tags = $query->orderBy('parent_id')->orderBy('id')->get();

        return $this->apiSuccess($tags, 'Tags retrieved successfully');
    }


    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:tags,name',
            'parent_id' => 'sometimes|int|exists:tags,id',
        ]);

        $tag = Tag::create([
            'name' => $validated['name'],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        if(isset($validated['parent_id']) && $validated['parent_id'] !== 0){
            $tag->parent_id = $validated['parent_id'];
            $tag->save();
        }

        return $this->apiSuccess($tag, 'Tag created successfully');
    }

    public function show(int $id)
    {
        $tag = Tag::with(['creator:id,name', 'updater:id,name', 'ayahs:id,text'])->findOrFail($id);

        return $this->apiSuccess($tag, 'Tag retrieved successfully');
    }

    public function update(Request $request, int $id)
    {
        $tag = Tag::query()->findOrFail($id);

        $validated = $request->validate([
            'parent_id' => 'sometimes|int|exists:tags,id',
            'name' => 'sometimes|string|unique:tags,name',
        ]);

        $tag->update(array_merge($validated, ['updated_by' => Auth::id()]));

        return $this->apiSuccess($tag, 'Tag updated successfully');
    }

    public function destroy(int $id)
    {
        $tag = Tag::find($id);

        if (!$tag) {
            return $this->apiError('Tag not found', 404);
        }

        $tag->delete();
        return $this->apiSuccess(null, 'Tag deleted successfully');
    }

    public function attachAyahTag(Request $request)
    {
        $validated = $request->validate([
            'ayah_id' => 'required|int|exists:ayahs,id',
            'tag_id' => 'required|int|exists:tags,id',
            'notes' => 'nullable|string'
        ]);

        $ayah = Ayah::query()->find($validated['ayah_id']);
        $tag = Tag::query()->find($validated['tag_id']);

        // Check if the tag is already assigned to this ayah
        if ($ayah->tags()->where('tag_id', $tag->id)->exists()) {
            return $this->apiError("This tag is already associated with the ayah", 409);
        }

        // Attach the tag to the ayah with additional metadata
        $ayah->tags()->attach($tag->id, [
            'notes' => $validated['notes'] ?? null,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id()
        ]);

        return $this->apiSuccess($ayah->tags, "Tag associated with Ayah successfully");
    }

    public function createTagAndAttachToAyah(Request $request)
    {
        $validated = $request->validate([
            'ayah_id' => 'required|int|exists:ayahs,id',
            'name' => 'required|string',
            'parent_id' => 'sometimes|int',
            'notes' => 'nullable|string'
        ]);

        // Find or create the tag
        $tag = Tag::query()->firstOrCreate(
            [
                'name' => $validated['name'],
                'parent_id' => $validated['parent_id'] ?? null,
            ],
            [
                'created_by' => Auth::id(),
                'updated_by' => Auth::id()
            ]
        );

        $ayah = Ayah::query()->find($validated['ayah_id']);

        // Check if the tag is already associated with the ayah
        if ($ayah->tags()->where('tag_id', $tag->id)->exists()) {
            return $this->apiError("This tag is already associated with the ayah", 409);
        }

        // Attach the tag to the ayah with metadata
        $ayah->tags()->attach($tag->id, [
            'notes' => $validated['notes'] ?? null,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id()
        ]);

        return $this->apiSuccess(
            ['ayah' => $ayah, 'tag' => $tag],
            "Tag created (if necessary) and associated with Ayah successfully"
        );
    }

}
