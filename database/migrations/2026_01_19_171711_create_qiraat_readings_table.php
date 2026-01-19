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
        Schema::create('qiraat_readings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->text('imam'); // e.g. Nafi'
            $table->text('riwaya'); // e.g. Warsh
            $table->text('rawi')->nullable(); // optional
            $table->text('name'); // display name

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qiraat_readings');
    }
};
