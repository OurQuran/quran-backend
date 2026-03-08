<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Precompiled difference data: one row per ayah (whole Quran) per qiraat.
     * For qiraat 2+ uses mushaf_ayah_id; for qiraat 1 (base) uses ayah_id (ayahs table).
     * Structure mirrors mushaf_ayahs; ayah_template is full ayah HTML with
     * class "qiraat-diff" on spans for words that differ. Includes text, pure_text, etc.
     */
    public function up(): void
    {
        Schema::create('qiraat_diff_ayahs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('qiraat_reading_id');
            $table->unsignedBigInteger('mushaf_ayah_id')->nullable();
            $table->unsignedBigInteger('ayah_id')->nullable();

            $table->unsignedSmallInteger('surah_id')->nullable();
            $table->unsignedSmallInteger('number_in_surah')->nullable();
            $table->unsignedSmallInteger('page')->nullable();
            $table->unsignedSmallInteger('hizb_id')->nullable();
            $table->unsignedSmallInteger('juz_id')->nullable();

            $table->text('text')->nullable();
            $table->text('pure_text')->nullable();
            $table->text('ayah_template')->nullable();

            $table->timestamps();

            $table->unique(['qiraat_reading_id', 'mushaf_ayah_id'], 'qiraat_diff_ayahs_reading_mushaf_unique');
            $table->unique(['qiraat_reading_id', 'ayah_id'], 'qiraat_diff_ayahs_reading_base_unique');
            $table->index(['qiraat_reading_id', 'surah_id', 'number_in_surah']);
            $table->index(['qiraat_reading_id', 'page']);

            $table->foreign('qiraat_reading_id')
                ->references('id')->on('qiraat_readings')
                ->onDelete('cascade');
            $table->foreign('mushaf_ayah_id')
                ->references('id')->on('mushaf_ayahs')
                ->onDelete('cascade');
            $table->foreign('ayah_id')
                ->references('id')->on('ayahs')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qiraat_diff_ayahs');
    }
};
