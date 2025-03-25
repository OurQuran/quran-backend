<?php

namespace App\Http\Controllers;

use App\Models\Ayah;
use App\Models\AyahTag;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TagController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100'
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        // Query only parent tags
        $query = Tag::query()
            ->whereNull('parent_id')
            ->with('allChildren') // Load children
            ->orderBy('name')
            ->orderBy('id');

        // Filter by name (case-insensitive)
        if (!empty($validated['name'])) {
            $query->whereRaw('name ILIKE ?', ["%{$validated['name']}%"]);
        }

        // Count total parent tags before pagination
        $totalCount = $query->count();
        $totalPages = ceil($totalCount / $perPage);

        // Paginate parent tags
        $tags = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return $this->apiSuccess([
            'meta' => [
                'total_count' => $totalCount,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'page_size' => $perPage
            ],
            'result' => $tags
        ], 'Tags retrieved successfully');
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

        return $this->apiSuccess($tag, 'Tag created successfully', 201);
    }

    public function show(int $id)
    {
        $tag = Tag::query()->with('allChildren')->findOrFail($id);

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
        $tag = Tag::query()->findOrFail($id);

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
            "Tag created (if necessary) and associated with Ayah successfully",
            201
        );
    }

    public function getAyahsAssociatedWithTag(Request $request) {
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'tag_id' => 'sometimes|integer|exists:tags,id',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100'
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        // If 'name' or 'tag_id' is provided, filter the specific tag
        if (!empty($validated['name']) || !empty($validated['tag_id'])) {
            $tagQuery = Tag::query();

            if (!empty($validated['name'])) {
                $tagQuery->whereRaw('name ILIKE ?', ["%{$validated['name']}%"]);
            }

            if (!empty($validated['tag_id'])) {
                $tagQuery->where('id', $validated['tag_id']);
            }

            $tag = $tagQuery->firstOrFail();

            // Retrieve ayahs related to the specific tag with pagination
            $ayahs = $tag->ayahs()
                ->select(['ayahs.id', 'ayahs.text', 'ayahs.number_in_surah', 'ayahs.surah_id', 'ayahs.page', 'ayahs.hizb_id', 'ayahs.juz_id', 'ayahs.sajda', 'ayahs.ayah_template'])
                ->paginate($perPage, ['*'], 'page', $page);

            if ($ayahs->isEmpty()) {
                return $this->apiError('No Ayahs are attached to this tag', 404);
            }

            // Add tag_name to each ayah and hide unnecessary fields
            foreach ($ayahs as $ayah) {
                $ayah->tag_name = $tag->name;
                $ayah->makeHidden(['hizb_id', 'juz_id', 'sajda', 'ayah_template']);
                $ayah->makeHidden('pivot'); // Hide pivot data
            }

            return $this->apiSuccess([
                'meta' => [
                    'total_count' => $ayahs->total(),
                    'total_pages' => $ayahs->lastPage(),
                    'current_page' => $ayahs->currentPage(),
                    'page_size' => $ayahs->perPage()
                ],
                'result' => $ayahs->items()
            ], 'Ayahs retrieved successfully');
        }

        // If no name or tag_id is provided, return ayahs with tag_name added
        $tagsWithAyahs = Tag::with(['ayahs' => function ($query) {
            $query->select(['ayahs.id', 'ayahs.text', 'ayahs.number_in_surah', 'ayahs.surah_id', 'ayahs.page', 'ayahs.hizb_id', 'ayahs.juz_id', 'ayahs.sajda', 'ayahs.ayah_template']);
        }])->get();

        // If no ayahs exist for any tag, return a message
        if ($tagsWithAyahs->isEmpty()) {
            return $this->apiError('No Ayahs are attached to any tag', 404);
        }

        // Flatten the result, adding the tag_name to each ayah and hiding the unnecessary fields
        $ayahsWithTagName = [];
        $totalAyahsCount = 0;

        foreach ($tagsWithAyahs as $tag) {
            // Count the total ayahs for each tag before pagination
            $totalAyahsCount += $tag->ayahs()->count();

            foreach ($tag->ayahs as $ayah) {
                $ayah->tag_name = $tag->name;
                $ayah->makeHidden(['hizb_id', 'juz_id', 'sajda', 'ayah_template']);
                $ayah->makeHidden('pivot'); // Hide pivot data
                $ayahsWithTagName[] = $ayah;
            }
        }

        // Calculate total pages
        $totalPages = ceil($totalAyahsCount / $perPage);

        // Apply pagination manually
        $paginatedResult = array_slice($ayahsWithTagName, ($page - 1) * $perPage, $perPage);

        return $this->apiSuccess([
            'meta' => [
                'total_count' => $totalAyahsCount,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'page_size' => $perPage
            ],
            'result' => $paginatedResult
        ], 'Ayahs retrieved successfully');
    }


    public function getUnapprovedTags(Request $request) {
        $validated = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100'
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $unapprovedTagsQuery = AyahTag::query()
            ->where('approved_by', '=', null)
            ->orWhere('approved_at', '=', null)
            ->orderBy('updated_at', 'desc');

        $totalCount = $unapprovedTagsQuery->count();
        $totalPages = ceil($totalCount / $perPage);

        $unapprovedTags = $unapprovedTagsQuery->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return $this->apiSuccess([
            'meta' => [
                'total_count' => $totalCount,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'page_size' => $perPage
            ],
            'result' => $unapprovedTags
        ], 'Unapproved associated Tags with Ayahs retrieved successfully');
    }

    public function approve(Request $request){
        $validated = $request->validate([
            'id' => 'required|integer|exists:ayah_tags,id'
        ]);

        $ayahTag = AyahTag::query()->findOrFail($validated['id']);

        if ($ayahTag->approved_at != null || $ayahTag->approved_by != null){
           return $this->apiError('This tag is already approved'    );
        }

        $ayahTag->approved_at = now();
        $ayahTag->approved_by = Auth::id();

        $ayahTag->save();

        return $this->apiSuccess($ayahTag, 'Tag approved successfully');
    }

    public function unapprove(Request $request){
        $validated = $request->validate([
            'id' => 'required|integer|exists:ayah_tags,id'
        ]);

        $ayahTag = AyahTag::query()->findOrFail($validated['id']);

        if ($ayahTag->approved_at == null || $ayahTag->approved_by == null){
            return $this->apiError('This tag is already unapproved');
        }

        $ayahTag->approved_at = null;
        $ayahTag->approved_by = null;

        $ayahTag->save();

        return $this->apiSuccess($ayahTag, 'Tag unapproved successfully');
    }
}
