<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mushaf_word_to_word_map', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('mushaf_word_id');
            $table->unsignedBigInteger('word_id');

            $table->string('map_type', 16)->default('exact'); // exact|split|combined
            $table->unsignedInteger('part_no')->nullable();     // for split
            $table->unsignedInteger('parts_total')->nullable(); // split/combined
            $table->unsignedInteger('word_order')->nullable();  // for combined order on base side

            // Optional but VERY useful for auditing
            $table->unsignedBigInteger('qiraat_difference_id')->nullable();
            $table->string('match_method', 32)->nullable(); // exact_norm|combined_k|split_k|dp_block|...
            $table->decimal('confidence', 4, 3)->nullable(); // 0..1

            $table->timestamps();

            $table->foreign('mushaf_word_id')->references('id')->on('mushaf_words')->onDelete('cascade');
            $table->foreign('word_id')->references('id')->on('words')->onDelete('cascade');
            $table->foreign('qiraat_difference_id')->references('id')->on('qiraat_differences')->nullOnDelete();

            $table->unique(['mushaf_word_id', 'word_id'], 'mw2w_unique_pair');

            $table->index(['mushaf_word_id'], 'mw2w_mushaf_idx');
            $table->index(['word_id'], 'mw2w_word_idx');
            $table->index(['qiraat_difference_id'], 'mw2w_diff_idx');
        });

        // checks (Postgres) are optional; if you want parity with ayah map, add them via DB::statement
    }

    public function down(): void
    {
        Schema::dropIfExists('mushaf_word_to_word_map');
    }
};
