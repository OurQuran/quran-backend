<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add support for qiraat 1 (base from ayahs table): ayah_id in qiraat_diff_ayahs,
     * word_id in qiraat_diff_words; make mushaf_* nullable. Add hizb_id, juz_id to diff_ayahs.
     * Run only if you already have qiraat_diff_ayahs/qiraat_diff_words from an earlier migration.
     */
    public function up(): void
    {
        if (!Schema::hasTable('qiraat_diff_ayahs')) {
            return;
        }

        Schema::table('qiraat_diff_ayahs', function (Blueprint $table) {
            if (!Schema::hasColumn('qiraat_diff_ayahs', 'ayah_id')) {
                $table->unsignedBigInteger('ayah_id')->nullable()->after('mushaf_ayah_id');
                $table->foreign('ayah_id')->references('id')->on('ayahs')->onDelete('cascade');
                $table->unique(['qiraat_reading_id', 'ayah_id'], 'qiraat_diff_ayahs_reading_base_unique');
            }
            if (!Schema::hasColumn('qiraat_diff_ayahs', 'hizb_id')) {
                $table->unsignedSmallInteger('hizb_id')->nullable()->after('page');
            }
            if (!Schema::hasColumn('qiraat_diff_ayahs', 'juz_id')) {
                $table->unsignedSmallInteger('juz_id')->nullable()->after('hizb_id');
            }
        });

        if (Schema::hasColumn('qiraat_diff_ayahs', 'mushaf_ayah_id')) {
            Schema::table('qiraat_diff_ayahs', function (Blueprint $table) {
                $table->unsignedBigInteger('mushaf_ayah_id')->nullable()->change();
            });
        }

        if (!Schema::hasTable('qiraat_diff_words')) {
            return;
        }

        Schema::table('qiraat_diff_words', function (Blueprint $table) {
            if (!Schema::hasColumn('qiraat_diff_words', 'word_id')) {
                $table->unsignedBigInteger('word_id')->nullable()->after('mushaf_word_id');
                $table->foreign('word_id')->references('id')->on('words')->onDelete('cascade');
                $table->unique(['qiraat_diff_ayah_id', 'word_id'], 'qiraat_diff_words_base_unique');
            }
        });

        if (Schema::hasColumn('qiraat_diff_words', 'mushaf_word_id')) {
            Schema::table('qiraat_diff_words', function (Blueprint $table) {
                $table->unsignedBigInteger('mushaf_word_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('qiraat_diff_ayahs')) {
            return;
        }
        Schema::table('qiraat_diff_ayahs', function (Blueprint $table) {
            $table->dropUnique('qiraat_diff_ayahs_reading_base_unique');
            $table->dropForeign(['ayah_id']);
            if (Schema::hasColumn('qiraat_diff_ayahs', 'ayah_id')) {
                $table->dropColumn('ayah_id');
            }
            if (Schema::hasColumn('qiraat_diff_ayahs', 'hizb_id')) {
                $table->dropColumn('hizb_id');
            }
            if (Schema::hasColumn('qiraat_diff_ayahs', 'juz_id')) {
                $table->dropColumn('juz_id');
            }
        });
        if (Schema::hasTable('qiraat_diff_words')) {
            Schema::table('qiraat_diff_words', function (Blueprint $table) {
                $table->dropUnique('qiraat_diff_words_base_unique');
                $table->dropForeign(['word_id']);
                if (Schema::hasColumn('qiraat_diff_words', 'word_id')) {
                    $table->dropColumn('word_id');
                }
            });
        }
    }
};
