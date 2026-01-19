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
        Schema::create('ayah_audio_meta', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Links metadata to a single ayah_edition row (the one with is_audio = 1)
            $table->unsignedBigInteger('ayah_edition_id');

            // Audio metadata
            $table->integer('duration_ms')->nullable();                 // audio duration in milliseconds
            $table->unsignedBigInteger('file_size_bytes')->nullable();  // file size in bytes
            $table->text('mime_type')->nullable();                // e.g. audio/mpeg

            $table->timestamps();

            // One meta record per ayah_edition audio row
            $table->unique('ayah_edition_id', 'ayah_audio_meta_unique');

            $table->foreign('ayah_edition_id', 'ayah_audio_meta_ayah_edition_fk')
                ->references('id')->on('ayah_edition');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ayah_audio_meta');
    }
};
