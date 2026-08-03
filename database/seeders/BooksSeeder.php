<?php

namespace Database\Seeders;

use App\Support\QiraatImportMaps;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BooksSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $books = QiraatImportMaps::seededBooks();

        foreach ($books as $b) {
            DB::table('books')->updateOrInsert(
                ['name' => $b['name']],
                [
                    'pdf_path' => $b['pdf_path'] ?? null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            if (!Schema::hasTable('book_people')) {
                continue;
            }

            $bookId = DB::table('books')->where('name', $b['name'])->value('id');
            $people = $this->peopleRowsForBook((int) $bookId, $b, $now);

            if ($people !== []) {
                DB::table('book_people')->upsert(
                    $people,
                    ['book_id', 'role', 'name'],
                    ['order_no', 'updated_at']
                );
            }
        }
    }

    private function peopleRowsForBook(int $bookId, array $book, mixed $now): array
    {
        $rows = [];

        foreach ($this->peopleNames($book, 'authors') as $index => $name) {
            $rows[] = [
                'book_id' => $bookId,
                'role' => 'author',
                'name' => $name,
                'order_no' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($this->peopleNames($book, 'supervisors') as $index => $name) {
            $rows[] = [
                'book_id' => $bookId,
                'role' => 'supervisor',
                'name' => $name,
                'order_no' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    private function peopleNames(array $book, string $pluralKey): array
    {
        if (!empty($book[$pluralKey]) && is_array($book[$pluralKey])) {
            return array_values(array_filter(array_map(
                fn ($person) => is_array($person) ? ($person['name'] ?? null) : $person,
                $book[$pluralKey]
            )));
        }

        return [];
    }
}
