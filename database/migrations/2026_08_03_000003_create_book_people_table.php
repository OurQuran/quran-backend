<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('book_people')) {
            Schema::create('book_people', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('book_id');
                $table->string('role', 50);
                $table->string('name');
                $table->unsignedInteger('order_no')->default(1);
                $table->timestamps();

                $table->foreign('book_id')
                    ->references('id')->on('books')
                    ->onDelete('cascade');

                $table->unique(['book_id', 'role', 'name'], 'book_people_book_role_name_unique');
                $table->index(['book_id', 'role', 'order_no']);
            });
        }

        $now = now();
        $rows = [];

        foreach ($this->bookPeopleMetadata() as $bookPeople) {
            $bookId = DB::table('books')->where('name', $bookPeople['book_name'])->value('id');
            if (!$bookId) {
                continue;
            }

            foreach ($bookPeople['authors'] as $index => $name) {
                if ($name !== '') {
                    $rows[] = [
                        'book_id' => $bookId,
                        'role' => 'author',
                        'name' => $name,
                        'order_no' => $index + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach ($bookPeople['supervisors'] as $index => $name) {
                if ($name !== '') {
                    $rows[] = [
                        'book_id' => $bookId,
                        'role' => 'supervisor',
                        'name' => $name,
                        'order_no' => $index + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        if ($rows !== []) {
            DB::table('book_people')->upsert(
                $rows,
                ['book_id', 'role', 'name'],
                ['order_no', 'updated_at']
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('book_people');
    }

    private function bookPeopleMetadata(): array
    {
        $authorName = 'د. احمد ضیف اللە عمر أبو سمهدانە';
        $supervisorName = 'شیح نظام الدین یونس عزیز';

        return array_map(
            fn (string $bookName) => [
                'book_name' => $bookName,
                'authors' => [$authorName],
                'supervisors' => [$supervisorName],
            ],
            [
                'القراءات - ابن كثير المكي (البزي وقنبل)',
                'القراءات - أبو عمرو البصري (الدوري والسوسي)',
                'رواية قالون عن نافع',
                'رواية ورش عن نافع',
                'القراءات - ابن عامر الشامي (هشام وابن ذكوان)',
                'رواية شعبة عن عاصم',
                'القراءات - حمزة الزيات (خلف وخلاد)',
                'القراءات - الكسائي الكوفي (أبو الحارث والدوري)',
                'القراءات - أبو جعفر المدني (ابن وردان وابن جماز)',
                'القراءات - يعقوب الحضرمي (رويس وروح)',
                'القراءات - خلف العاشر (إسحاق وإدريس)',
            ]
        );
    }
};
