<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ayahs', function (Blueprint $table) {
            if (! Schema::hasColumn('ayahs', 'qcf_tajweed_template')) {
                $table->text('qcf_tajweed_template')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ayahs', function (Blueprint $table) {
            if (Schema::hasColumn('ayahs', 'qcf_tajweed_template')) {
                $table->dropColumn('qcf_tajweed_template');
            }
        });
    }
};
