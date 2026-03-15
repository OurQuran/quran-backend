<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('book_sections', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('book_id');
            $table->unsignedInteger('order_no');

            $table->text('header_text')->nullable();
            $table->json('images')->nullable();
            $table->longText('body_text');

            $table->timestamps();

            $table->foreign('book_id')
                ->references('id')->on('books')
                ->onDelete('cascade');

            $table->unique(['book_id', 'order_no']);
            $table->index(['book_id', 'order_no']);
        });

        Schema::create('book_section_refs', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('book_section_id');
            $table->unsignedInteger('ref_no');

            $table->text('ref_text');
            $table->jsonb('cite_offsets'); // [{start,end}, ...]

            $table->timestamps();

            $table->foreign('book_section_id')
                ->references('id')->on('book_sections')
                ->onDelete('cascade');

            $table->unique(['book_section_id', 'ref_no']);
            $table->index(['book_section_id', 'ref_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_section_refs');
        Schema::dropIfExists('book_sections');
        Schema::dropIfExists('books');
    }
};
