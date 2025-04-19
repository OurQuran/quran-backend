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
            'name' => 'sometimes|nullable|string',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'user_id' => 'sometimes|nullable|integer|min:1|exists:users,id'
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        // Query only parent tags
        $query = Tag::query()
            ->with('allChildren') // Load children
            ->orderBy('name')
            ->orderBy('id');

        // Filter by name (case-insensitive)
        if (!empty($validated['name'])) {
            $query->whereRaw('name ILIKE ?', ["%{$validated['name']}%"]);
        } else {
            $query->whereNull('parent_id');
        }

        $user = $this->checkLoginToken();

        if (!empty($validated['user_id'])) {
            if ($validated['user_id'] == $user->id || $user->role == 'superadmin' || $user->role == 'admin')
                $query->whereRaw('created_by = ?', ["{$validated['user_id']}"]);
            else
                return $this->apiError('You do not have the permission to view this', 403);
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

    public function show(Tag $tag)
    {
        $tag->load([
            'allChildren' => function ($query) {
                $query->with('allChildren');
            },
            'ayahs.tags' => function ($query) {
                $query->select('tags.id', 'tags.name');
            }
        ]);

        $tag->ayahs->each(function ($ayah) {
            $ayah->makeHidden('pivot');
            $ayah->tags = $ayah->tags->makeHidden('pivot');
        });

        if (!$tag->relationLoaded('ayahs') || $tag->ayahs->isEmpty()) {
            $tag->setRelation('ayahs', collect([]));
        }

        $this->addEmptyAyahsToChildren($tag->allChildren);

        return $this->apiSuccess($tag, 'Tag retrieved successfully');
    }


    public function update(Request $request, Tag $tag)
    {
        $validated = $request->validate([
            'parent_id' => 'sometimes|nullable|int|exists:tags,id',
            'name' => 'sometimes|nullable|string|unique:tags,name',
        ]);

        $tag->update(array_merge($validated, ['updated_by' => Auth::id()]));

        return $this->apiSuccess($tag, 'Tag updated successfully');
    }

    public function destroy(Tag $tag)
    {
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
            'parent_id' => 'sometimes|nullable|int',
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

    public function searchTags(Request $request){
        $validated = $request->validate([
            'name' => 'sometimes|nullable|string',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100'
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $query= Tag::query()
            ->select('id','name')
            ->skip(($page - 1) * $perPage)
            ->take($perPage);

        if (!empty($validated['name'])) {
            $query->whereRaw('name ILIKE ?', ["%{$validated['name']}%"]);
        }

        $totalCount = $query->count();
        $totalPages = ceil($totalCount / $perPage);

        $tags = $query->get();

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

    public function scrape(Request $request)
    {
        $validated = $request->validate([
            'surah_id' => 'required|integer|exists:surahs,id',
            'verse' => [
                'required',
                function ($attribute, $value, $fail) {
                    $verses = is_array($value) ? $value : [$value];
                    foreach ($verses as $v) {
                        if (!is_numeric($v) || (int)$v < 1) {
                            $fail("Each $attribute must be an integer >= 1.");
                        }
                    }
                }
            ],
            'tag_name' => 'required|string'
        ]);

        $verses = is_array($validated['verse']) ? $validated['verse'] : [$validated['verse']];
        $tag = Tag::firstOrCreate(['name' => $validated['tag_name']]);

        $results = [];

        foreach ($verses as $verseNumber) {
            $ayah = Ayah::where('surah_id', $validated['surah_id'])
                ->where('number_in_surah', $verseNumber)
                ->first();

            if (!$ayah) {
                $results[] = [
                    'verse' => $verseNumber,
                    'status' => 'not found',
                    'message' => "Verse $verseNumber not found."
                ];
                continue;
            }

            $alreadyAttached = $ayah->tags()->where('tags.id', $tag->id)->exists();

            if ($alreadyAttached) {
                $results[] = [
                    'ayah_id' => $ayah->id,
                    'verse' => $verseNumber,
                    'status' => 'skipped',
                    'message' => "Tag '{$tag->name}' already attached."
                ];
                continue;
            }

            $ayah->tags()->attach($tag->id);

            $results[] = [
                'ayah_id' => $ayah->id,
                'verse' => $verseNumber,
                'tag_id' => $tag->id,
                'tag_name' => $tag->name,
                'status' => 'success',
                'message' => "Tag '{$tag->name}' attached."
            ];
        }

        return $this->apiSuccess($results, 'Scraping/tagging completed.');
    }

    //
    public function scrapeArray(Request $request)
    {
        $validated = $request->validate([
            'references' => 'required|array',
            'references.*' => 'required|string|regex:/^\d+:[\d,\-\s]+$/',
            'tag_name' => 'required_without:tag_id|string|nullable',
            'tag_id' => 'required_without:tag_name|integer|exists:tags,id|nullable',
        ]);

        if (!empty($validated['tag_name'] ?? null) && !empty($validated['tag_id'] ?? null)) {
            return $this->apiError('Provide either tag_name or tag_id, not both.', 422);
        }

        // Get the tag
        if (!empty($validated['tag_id'])) {
            $tag = Tag::find($validated['tag_id']);
        } else {
            $tag = Tag::firstOrCreate(['name' => $validated['tag_name']]);
        }

        $results = [];

        foreach ($validated['references'] as $refString) {
            [$surahId, $versesPart] = explode(':', $refString);

            $verseParts = explode(',', $versesPart);
            $verseNumbers = [];

            foreach ($verseParts as $part) {
                $part = trim($part);
                if (str_contains($part, '-')) {
                    [$start, $end] = explode('-', $part);
                    $start = (int) trim($start);
                    $end = (int) trim($end);
                    if ($start <= $end) {
                        $verseNumbers = array_merge($verseNumbers, range($start, $end));
                    }
                } else {
                    $verseNumbers[] = (int) $part;
                }
            }

            foreach ($verseNumbers as $verseNumber) {
                $ayah = Ayah::where('surah_id', $surahId)
                    ->where('number_in_surah', $verseNumber)
                    ->first();

                if (!$ayah) {
                    $results[] = [
                        'surah' => (int) $surahId,
                        'verse' => (int) $verseNumber,
                        'status' => 'not_found',
                        'message' => "Ayah $surahId:$verseNumber not found."
                    ];
                    continue;
                }

                $alreadyAttached = $ayah->tags()->where('tags.id', $tag->id)->exists();

                if ($alreadyAttached) {
                    $results[] = [
                        'ayah_id' => $ayah->id,
                        'surah' => (int) $surahId,
                        'verse' => (int) $verseNumber,
                        'status' => 'skipped',
                        'message' => "Tag already attached to $surahId:$verseNumber."
                    ];
                    continue;
                }

                $ayah->tags()->attach($tag->id);

                $results[] = [
                    'ayah_id' => $ayah->id,
                    'surah' => (int) $surahId,
                    'verse' => (int) $verseNumber,
                    'tag_id' => $tag->id,
                    'tag_name' => $tag->name,
                    'status' => 'success',
                    'message' => "Tagged $surahId:$verseNumber."
                ];
            }
        }

        return $this->apiSuccess($results, 'Tagging completed.');
    }

    /**
     * Recursively set ayahs as an empty array for all children if not already set.
     */
    private function addEmptyAyahsToChildren($children)
    {
        foreach ($children as $child) {
            if (!$child->relationLoaded('ayahs') || $child->ayahs->isEmpty()) {
                $child->setRelation('ayahs', collect([]));
            }

            // Recursively apply to nested children
            if ($child->relationLoaded('allChildren')) {
                $this->addEmptyAyahsToChildren($child->allChildren);
            }
        }
    }
}
