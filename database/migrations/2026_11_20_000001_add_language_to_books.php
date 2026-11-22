<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('books', 'language')) {
            Schema::table('books', function (Blueprint $table) {
                // Existing books are the Kurdish editions; Arabic editions use 'ar'.
                $table->string('language', 5)->default('ku')->after('name');
                $table->index('language');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('books', 'language')) {
            Schema::table('books', function (Blueprint $table) {
                $table->dropIndex(['language']);
                $table->dropColumn('language');
            });
        }
    }
};
