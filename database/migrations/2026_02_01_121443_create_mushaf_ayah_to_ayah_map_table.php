<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mushaf_ayah_to_ayah_map', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('mushaf_ayah_id');
            $table->unsignedBigInteger('ayah_id');

            $table->string('map_type', 16)->default('exact'); // exact|split|combined
            $table->unsignedInteger('part_no')->nullable();
            $table->unsignedInteger('parts_total')->nullable();
            $table->unsignedInteger('ayah_order')->nullable();

            $table->timestamps();

            $table->foreign('mushaf_ayah_id')->references('id')->on('mushaf_ayahs')->onDelete('cascade');
            $table->foreign('ayah_id')->references('id')->on('ayahs')->onDelete('cascade');

            $table->unique(['mushaf_ayah_id', 'ayah_id'], 'mushaf_ayah_to_ayah_map_unique');

            $table->index('mushaf_ayah_id', 'mushaf_ayah_to_ayah_map_mushaf_idx');
            $table->index('ayah_id', 'mushaf_ayah_to_ayah_map_ayah_idx');

            // Optional helper index for lookups by ayah + type
            $table->index(['ayah_id', 'map_type'], 'mushaf_ayah_to_ayah_map_ayah_type_idx');
        });

        // Postgres check constraints (optional but strongly recommended)
        DB::statement("
            ALTER TABLE mushaf_ayah_to_ayah_map
            ADD CONSTRAINT mushaf_ayah_to_ayah_map_type_check
            CHECK (map_type IN ('exact','split','combined'))
        ");

        DB::statement("
            ALTER TABLE mushaf_ayah_to_ayah_map
            ADD CONSTRAINT mushaf_ayah_to_ayah_map_split_check
            CHECK (
                (map_type <> 'split')
                OR
                (part_no IS NOT NULL AND parts_total IS NOT NULL AND part_no >= 1 AND parts_total >= 2 AND part_no <= parts_total)
            )
        ");

        DB::statement("
            ALTER TABLE mushaf_ayah_to_ayah_map
            ADD CONSTRAINT mushaf_ayah_to_ayah_map_combined_check
            CHECK (
                (map_type <> 'combined')
                OR
                (ayah_order IS NOT NULL AND ayah_order >= 1)
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('mushaf_ayah_to_ayah_map');
    }
};
