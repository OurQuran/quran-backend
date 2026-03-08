<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Precompiled difference spans: one row per word that is "different" in that qiraat
     * (or per word when from base: qiraat 1 from ayahs uses word_id). word_template
     * has class "qiraat-diff" for highlight. Structure mirrors mushaf_words; includes
     * word, pure_word.
     */
    public function up(): void
    {
        Schema::create('qiraat_diff_words', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('qiraat_diff_ayah_id');
            $table->unsignedBigInteger('mushaf_word_id')->nullable();
            $table->unsignedBigInteger('word_id')->nullable();

            $table->unsignedInteger('position')->nullable();
            $table->text('word')->nullable();
            $table->text('word_template')->nullable();
            $table->text('pure_word')->nullable();

            $table->timestamps();

            $table->unique(['qiraat_diff_ayah_id', 'mushaf_word_id'], 'qiraat_diff_words_mushaf_unique');
            $table->unique(['qiraat_diff_ayah_id', 'word_id'], 'qiraat_diff_words_base_unique');
            $table->index(['qiraat_diff_ayah_id', 'position']);

            $table->foreign('qiraat_diff_ayah_id')
                ->references('id')->on('qiraat_diff_ayahs')
                ->onDelete('cascade');
            $table->foreign('mushaf_word_id')
                ->references('id')->on('mushaf_words')
                ->onDelete('cascade');
            $table->foreign('word_id')
                ->references('id')->on('words')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qiraat_diff_words');
    }
};
