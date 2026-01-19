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
        Schema::table('editions', function (Blueprint $table) {
            $table->unsignedBigInteger('qiraat_reading_id')->nullable();

            $table->index('qiraat_reading_id');

            $table->foreign('qiraat_reading_id')
                ->references('id')->on('qiraat_readings')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
            Schema::table('editions', function (Blueprint $table) {
                $table->dropForeign(['qiraat_reading_id']);
                $table->dropIndex(['qiraat_reading_id']);
                $table->dropColumn('qiraat_reading_id');
            });
    }
};
