<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BooksSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Add/adjust names however you want
        $books = [
            ['name' => 'تفسير ابن كثير'],
            ['name' => 'القراءات - ابن عامر'],
            ['name' => 'القراءات - أبو جعفر'],
            ['name' => 'القراءات - أبو عمرو'],
            ['name' => 'القراءات - حمزة'],
            ['name' => 'القراءات - خلف العاشر'],
            ['name' => 'القراءات - شعبة'],
            ['name' => 'القراءات - قالون'],
            ['name' => 'القراءات - الكسائي'],
            ['name' => 'القراءات - ورش'],
            ['name' => 'القراءات - يعقوب'],
        ];

        foreach ($books as $b) {
            DB::table('books')->updateOrInsert(
                ['name' => $b['name']],
                ['updated_at' => $now, 'created_at' => $now]
            );
        }
    }
}
