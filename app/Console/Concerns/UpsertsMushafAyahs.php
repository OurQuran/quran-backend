<?php

namespace App\Console\Concerns;

use Illuminate\Support\Facades\DB;

trait UpsertsMushafAyahs
{
    protected function upsertMushafAyahRows(array $rows, bool $dryRun): int
    {
        if (empty($rows)) {
            return 0;
        }

        if ($dryRun) {
            return count($rows);
        }

        DB::table('mushaf_ayahs')->upsert(
            $rows,
            ['qiraat_reading_id', 'surah_id', 'number_in_surah'],
            ['text', 'page', 'juz_id', 'hizb_id', 'sajda', 'ayah_template', 'pure_text']
        );

        return count($rows);
    }
}
