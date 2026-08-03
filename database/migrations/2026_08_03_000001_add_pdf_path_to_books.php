<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('books', 'pdf_path')) {
            Schema::table('books', function (Blueprint $table) {
                $table->string('pdf_path')->nullable()->after('name');
            });
        }

        foreach ($this->bookMetadata() as $book) {
            DB::table('books')
                ->where('name', $book['name'])
                ->update([
                    'pdf_path' => $book['pdf_path'],
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('books', 'pdf_path')) {
            Schema::table('books', function (Blueprint $table) {
                $table->dropColumn('pdf_path');
            });
        }
    }

    private function bookMetadata(): array
    {
        return [
            [
                'name' => 'القراءات - ابن كثير المكي (البزي وقنبل)',
                'pdf_path' => 'books/01 كتاب ابن كثير -النسخة الكردية.pdf',
            ],
            [
                'name' => 'القراءات - أبو عمرو البصري (الدوري والسوسي)',
                'pdf_path' => 'books/02 كتاب ابو عمرو البصري-النسخة الكردية-3.pdf',
            ],
            [
                'name' => 'رواية قالون عن نافع',
                'pdf_path' => 'books/03 كتاب قالون-النسخة الكردية.pdf',
            ],
            [
                'name' => 'رواية ورش عن نافع',
                'pdf_path' => 'books/04 كتاب ورش -النسخة الكردية.pdf',
            ],
            [
                'name' => 'القراءات - ابن عامر الشامي (هشام وابن ذكوان)',
                'pdf_path' => 'books/05 كتاب ابن عامر -النسخة الكردية.pdf',
            ],
            [
                'name' => 'رواية شعبة عن عاصم',
                'pdf_path' => 'books/06 كتاب شعبة -النسخة الكردية.pdf',
            ],
            [
                'name' => 'القراءات - حمزة الزيات (خلف وخلاد)',
                'pdf_path' => 'books/07 كتاب حمزة-النسخة الكردية.pdf',
            ],
            [
                'name' => 'القراءات - الكسائي الكوفي (أبو الحارث والدوري)',
                'pdf_path' => 'books/08 كتاب الكسائي -النسخة الكردية.pdf',
            ],
            [
                'name' => 'القراءات - أبو جعفر المدني (ابن وردان وابن جماز)',
                'pdf_path' => 'books/09 كتاب ابو جعفر-النسخة الكردية.pdf',
            ],
            [
                'name' => 'القراءات - يعقوب الحضرمي (رويس وروح)',
                'pdf_path' => 'books/10 كتاب يعقوب الحضرمي-النسخة الكردية.pdf',
            ],
            [
                'name' => 'القراءات - خلف العاشر (إسحاق وإدريس)',
                'pdf_path' => 'books/11 كتاب خلف العاشر -النسخة الكردية.pdf',
            ],
        ];
    }
};
