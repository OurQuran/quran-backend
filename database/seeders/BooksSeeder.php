<?php

namespace Database\Seeders;

use App\Support\QiraatImportMaps;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BooksSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $books = QiraatImportMaps::seededBooks();

        foreach ($books as $b) {
            DB::table('books')->updateOrInsert(
                ['name' => $b['name']],
                ['updated_at' => $now, 'created_at' => $now]
            );
        }
    }
}
