<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mushaf_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mushaf_ayah_id');
            $table->text('word')->nullable();
            $table->text('word_template')->nullable();
            $table->integer('position')->nullable();
            $table->text('pure_word')->nullable();

            $table->unique(['mushaf_ayah_id', 'position'], 'mushaf_words_ayah_pos_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mushaf_words');
    }
};
