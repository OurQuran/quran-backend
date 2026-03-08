<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qiraat_differences', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('qiraat_reading_id');
            $table->unsignedSmallInteger('surah');
            $table->unsignedSmallInteger('ayah');

            $table->text('hafs_text');
            $table->text('qiraat_options');
            $table->text('qiraat_text')->nullable();
            $table->text('explanation')->nullable();

            $table->timestamps();

            $table->unique(
                ['qiraat_reading_id', 'surah', 'ayah', 'hafs_text'],
                'qiraat_diff_reading_surah_ayah_hafs_unique'
            );
            $table->index(['qiraat_reading_id', 'surah', 'ayah']);

            $table->foreign('qiraat_reading_id')
                ->references('id')->on('qiraat_readings')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS qiraat_differences CASCADE');
    }
};
