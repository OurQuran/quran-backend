<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mushaf_ayahs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('qiraat_reading_id')->nullable();

            $table->text('text');
            $table->integer('number_in_surah')->nullable();
            $table->integer('page')->nullable();
            $table->integer('surah_id')->nullable();
            $table->integer('hizb_id')->nullable();
            $table->integer('juz_id')->nullable();
            $table->boolean('sajda')->nullable();
            $table->text('ayah_template')->nullable();
            $table->text('pure_text')->nullable();

            $table->unique(['qiraat_reading_id', 'surah_id', 'number_in_surah'], 'mushaf_ayahs_qiraat_surah_ayah_unique');
            $table->index(['qiraat_reading_id', 'surah_id', 'number_in_surah']);

            $table->foreign('qiraat_reading_id')
                ->references('id')->on('qiraat_readings')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mushaf_ayahs');
    }
};
