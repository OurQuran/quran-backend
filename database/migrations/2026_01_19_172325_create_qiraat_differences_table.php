<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qiraat_differences', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('qiraat_reading_id'); // which qiraat (Warsh/Qalun/etc)
            $table->unsignedBigInteger('ayah_id'); // which ayah this difference belongs to
            $table->unsignedBigInteger('start_word_id'); // first word affected (FK -> words.id)
            $table->unsignedBigInteger('end_word_id'); // last word affected (FK -> words.id). For single word: same as start_word_id

            $table->text('hafs_text'); // the qiraat word/phrase (Warsh form)
            $table->text('qiraat_options'); // the options separated by the comma
            $table->text('qiraat_text')->nullable(); // "Qiraa text" (store as text; can be same as qiraat_text if only one)
            $table->text('explanation')->nullable(); // explanation/notes (nullable because sometimes empty)

            $table->timestamps();

            // Prevent accidental duplicates
            $table->unique(
                ['qiraat_reading_id', 'ayah_id', 'start_word_id', 'end_word_id'],
                'qiraat_diff_unique'
            );

            $table->index(['qiraat_reading_id', 'ayah_id']);

            $table->foreign('qiraat_reading_id')
                ->references('id')->on('qiraat_readings')
                ->onDelete('cascade');

            $table->foreign('ayah_id')
                ->references('id')->on('ayahs')
                ->onDelete('cascade');

            $table->foreign('start_word_id')
                ->references('id')->on('words')
                ->onDelete('cascade');

            $table->foreign('end_word_id')
                ->references('id')->on('words')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qiraat_differences');
    }
};
