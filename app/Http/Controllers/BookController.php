<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookController extends Controller
{
    /**
     * GET /books
     * List all books with their section counts.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page'     => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $page    = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = DB::table('books')
            ->leftJoin('book_sections', 'books.id', '=', 'book_sections.book_id')
            ->select('books.id', 'books.name', DB::raw('COUNT(book_sections.id) AS section_count'))
            ->groupBy('books.id', 'books.name')
            ->orderBy('books.id');

        $totalCount = DB::table('books')->count();
        $totalPages = (int) ceil($totalCount / $perPage);

        $books = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return $this->apiSuccess([
            'meta' => [
                'total_count'  => $totalCount,
                'total_pages'  => $totalPages,
                'current_page' => $page,
                'page_size'    => $perPage,
            ],
            'books' => $books,
        ], 'Books retrieved successfully');
    }

    /**
     * GET /books/{id}
     * Return book info. Section listing is at GET /books/{id}/sections.
     */
    public function show(int $id)
    {
        $book = DB::table('books')->where('id', $id)->first();

        if (!$book) {
            return $this->apiError('Book not found', 404);
        }

        $sectionCount = DB::table('book_sections')->where('book_id', $id)->count();

        return $this->apiSuccess([
            'id'            => $book->id,
            'name'          => $book->name,
            'section_count' => $sectionCount,
        ], 'Book retrieved successfully');
    }

    /**
     * GET /books/{id}/sections?page=1&per_page=20
     * Paginated section list for a book. Images excluded; use showSection for full content.
     */
    public function sections(Request $request, int $id)
    {
        $book = DB::table('books')->where('id', $id)->first();

        if (!$book) {
            return $this->apiError('Book not found', 404);
        }

        $validated = $request->validate([
            'page'     => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $page    = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $totalCount = DB::table('book_sections')->where('book_id', $id)->count();
        $totalPages = (int) ceil($totalCount / $perPage);

        $sections = DB::table('book_sections')
            ->where('book_id', $id)
            ->select(
                'id', 'order_no', 'header_text', 'body_text',
                DB::raw("CASE WHEN images IS NOT NULL THEN json_array_length(images) ELSE 0 END AS image_count")
            )
            ->orderBy('order_no')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return $this->apiSuccess([
            'meta' => [
                'total_count'  => $totalCount,
                'total_pages'  => $totalPages,
                'current_page' => $page,
                'page_size'    => $perPage,
            ],
            'book'     => ['id' => $book->id, 'name' => $book->name],
            'sections' => $sections,
        ], 'Sections retrieved successfully');
    }

    /**
     * GET /books/{id}/sections/{order_no}?with_images=1
     * Full section content including footnote refs.
     * Pass ?with_images=1 to include base64 image data (can be large).
     */
    public function showSection(Request $request, int $id, int $orderNo)
    {
        $book = DB::table('books')->where('id', $id)->first();

        if (!$book) {
            return $this->apiError('Book not found', 404);
        }

        $section = DB::table('book_sections')
            ->where('book_id', $id)
            ->where('order_no', $orderNo)
            ->first();

        if (!$section) {
            return $this->apiError('Section not found', 404);
        }

        $refs = DB::table('book_section_refs')
            ->where('book_section_id', $section->id)
            ->select('ref_no', 'ref_text', 'cite_offsets')
            ->orderBy('ref_no')
            ->get()
            ->map(function ($ref) {
                $ref->cite_offsets = json_decode($ref->cite_offsets, true);
                return $ref;
            });

        $withImages = filter_var($request->query('with_images', false), FILTER_VALIDATE_BOOLEAN);

        $data = [
            'id'          => $section->id,
            'order_no'    => $section->order_no,
            'header_text' => $section->header_text,
            'body_text'   => $section->body_text,
            'refs'        => $refs,
        ];

        if ($withImages) {
            $data['images'] = $section->images ? json_decode($section->images, true) : [];
        } else {
            $imageCount = 0;
            if ($section->images) {
                $decoded = json_decode($section->images, true);
                $imageCount = is_array($decoded) ? count($decoded) : 0;
            }
            $data['image_count'] = $imageCount;
        }

        return $this->apiSuccess([
            'book'    => ['id' => $book->id, 'name' => $book->name],
            'section' => $data,
        ], 'Section retrieved successfully');
    }
}
