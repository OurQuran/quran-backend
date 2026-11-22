<?php

namespace Tests\Feature;

use Database\Seeders\BooksSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookAttributionImportTest extends TestCase
{
    public function test_arabic_import_adds_author_and_kurdish_seeder_does_not_change_arabic_book(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('books', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('language'); $table->string('pdf_path')->nullable(); $table->timestamps();
        });
        Schema::create('book_people', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('book_id'); $table->string('role'); $table->string('name');
            $table->integer('order_no'); $table->timestamps(); $table->unique(['book_id', 'role', 'name']);
        });
        Schema::create('book_sections', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('book_id'); $table->integer('order_no');
            $table->text('header_text')->nullable(); $table->text('body_text'); $table->text('images')->nullable();
            $table->timestamps(); $table->unique(['book_id', 'order_no']);
        });
        Schema::create('book_section_refs', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('book_section_id'); $table->integer('ref_no');
            $table->text('ref_text'); $table->text('cite_offsets'); $table->timestamps();
        });

        $dir = sys_get_temp_dir().'/quran-book-test-'.uniqid();
        mkdir($dir);
        $pdfDir = 'books/test_'.uniqid();
        mkdir(storage_path('app/private/'.$pdfDir), 0777, true);
        file_put_contents($dir.'/book.html', '<html><head><title>ابن عامر</title></head><body><h1>باب</h1><p>  تَجْرِبَة   [1] نص   <a class="footnote-ref" href="#fn1">1</a></p><p>   نص آخر <a class="footnote-ref" href="#fn1">1</a></p><section class="footnotes"><ol><li id="fn1">مرجع</li></ol></section></body></html>');
        try {
            $options = ['--dir' => $dir, '--language' => 'ar', '--pdf-dir' => $pdfDir];
            $this->artisan('books:import-html-folder', $options)->assertFailed();
            $this->assertSame(0, DB::table('books')->count());
            file_put_contents(storage_path('app/private/'.$pdfDir.'/book.pdf'), '%PDF-1.4 test');
            $this->artisan('books:import-html-folder', $options)->assertSuccessful();
            $this->artisan('books:import-html-folder', $options)->assertSuccessful();
            $this->artisan('books:import-html-folder', ['--dir' => $dir, '--language' => 'ar'])->assertSuccessful();
            $id = DB::table('books')->where('language', 'ar')->value('id');
            $this->assertSame($pdfDir.'/book.pdf', DB::table('books')->where('id', $id)->value('pdf_path'));
            $section = DB::table('book_sections')->where('book_id', $id)->first();
            $ref = DB::table('book_section_refs')->where('book_section_id', $section->id)->where('ref_no', 1)->first();
            $offsets = json_decode($ref->cite_offsets, true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(2, $offsets);
            foreach ($offsets as $offset) {
                $this->assertSame('[1]', mb_substr($section->body_text, $offset['start'], $offset['end'] - $offset['start']));
            }
            $this->assertGreaterThan(mb_strpos($section->body_text, '[1]'), $offsets[0]['start']);
            $this->assertSame(1, DB::table('book_people')->where('book_id', $id)->where('role', 'author')->where('name', 'د. أحمد ضيف الله أبو سمهدانة')->count());
            $this->assertSame(0, DB::table('book_people')->where('book_id', $id)->where('role', 'supervisor')->count());
            $this->artisan('books:import-html-folder', ['--dir' => $dir, '--language' => 'ku', '--pdf-dir' => $pdfDir])->assertSuccessful();
            $kurdishId = DB::table('books')->where('language', 'ku')->value('id');
            $this->assertSame(1, DB::table('book_people')->where('book_id', $kurdishId)->where('role', 'author')->count());
            $this->assertSame(1, DB::table('book_people')->where('book_id', $kurdishId)->where('role', 'supervisor')->count());
            $arabicPdf = DB::table('books')->where('id', $id)->value('pdf_path');
            (new BooksSeeder)->run();
            $this->assertSame($arabicPdf, DB::table('books')->where('id', $id)->value('pdf_path'));
            $this->assertSame($pdfDir.'/book.pdf', DB::table('books')->where('id', $kurdishId)->value('pdf_path'));
            $this->assertSame(1, DB::table('books')->where('name', DB::table('books')->where('id', $id)->value('name'))->where('language', 'ku')->count());
        } finally {
            @unlink(storage_path('app/private/'.$pdfDir.'/book.pdf'));
            rmdir(storage_path('app/private/'.$pdfDir));
            unlink($dir.'/book.html'); rmdir($dir);
        }
    }
}
