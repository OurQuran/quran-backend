<?php

namespace App\Http\Controllers;

use App\Models\Dictionary;
use Illuminate\Http\Request;

class DictionaryController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'word_id' => 'sometimes|nullable|integer|exists:words,id',
            'word' => 'sometimes|nullable|string|exists:words,word',
            'lang' => 'sometimes|nullable|string|max:10',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100'
        ]);

        $page = (int)($validated['page'] ?? 1);
        $perPage = (int)($validated['per_page'] ?? 20);

        $query = Dictionary::query()->with('word');

        if (!empty($validated['word_id'])) {
            $query->where('word_id', $validated['word_id']);
        }

        if (!empty($validated['word'])) {
            $query->whereRaw('word ILIKE ?', ["%{$validated['word']}%"]);
        }

        if (!empty($validated['lang'])) {
            $query->where('lang', $validated['lang']);
        }

        $totalCount = $query->count();
        $totalPages = ceil($totalCount / $perPage);

        $entries = $query->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return $this->apiSuccess([
            'meta' => [
                'total_count' => $totalCount,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'page_size' => $perPage
            ],
            'result' => $entries
        ], 'Dictionary entries retrieved successfully');
    }

    public function show(int $id)
    {
        $entry = Dictionary::with('word')->findOrFail($id);

        return $this->apiSuccess($entry, 'Dictionary entry retrieved successfully');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'word_id' => 'required|integer|exists:words,id',
            'meaning' => 'required|string',
            'lang' => 'required|string|max:10'
        ]);

        $existing = Dictionary::query()
            ->where('word_id', $validated['word_id'])
            ->where('meaning', $validated['meaning'])
            ->where('lang', $validated['lang'])
            ->first();

        if ($existing) {
            return $this->apiError('Dictionary entry already exists');
        }

        $entry = Dictionary::query()
            ->create([
                'word_id' => $validated['word_id'],
                'meaning' => $validated['meaning'],
                'lang' => $validated['lang'],
                'created_by' => auth()->id(),
                'updated_by' => auth()->id()
            ]);

        return $this->apiSuccess($entry, 'Dictionary entry created successfully', 201);
    }

    public function update(Request $request, int $id)
    {
        $entry = Dictionary::query()->findOrFail($id);

        $validated = $request->validate([
            'word_id' => 'sometimes|integer|exists:words,id',
            'meaning' => 'sometimes|string',
            'lang' => 'sometimes|string|max:10'
        ]);

        $entry->update(array_merge($validated, ['updated_by' => auth()->id()]));

        return $this->apiSuccess($entry, 'Dictionary entry updated successfully');
    }

    public function destroy($id)
    {
        $entry = Dictionary::query()->findOrFail($id);

        $entry->delete();

        return $this->apiSuccess(null, 'Dictionary entry deleted successfully');
    }
}
