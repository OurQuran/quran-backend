<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QiraatReadingsSeeder extends Seeder
{
    public function run(): void
    {
        // Canonical mapping (more accurate than some casual labels):
        // - Nafi'      -> Warsh, Qaloon
        // - Asim       -> Hafs, Shu'bah (shouba)
        // - Abu Amr    -> Doori, Soosi
        // - Ibn Kathir -> Bazzi, Qunbul (qumbul)

        $rows = [
            [
                'imam'   => "Asim",
                'riwaya' => "Hafs",
                'name'   => "Hafs 'an Asim",
            ],
            [
                'imam'   => "Nafi",
                'riwaya' => "Warsh",
                'name'   => "Warsh 'an Nafi",
            ],
            [
                'imam'   => "Asim",
                'riwaya' => "Shu'bah",
                'name'   => "Shu'bah 'an Asim",
            ],
            [
                'imam'   => "Nafi",
                'riwaya' => "Qaloon",
                'name'   => "Qaloon 'an Nafi",
            ],
            [
                'imam'   => "Abu Amr",
                'riwaya' => "Ad-Duri",
                'name'   => "Ad-Duri 'an Abu Amr",
            ],
            [
                'imam'   => "Abu Amr",
                'riwaya' => "As-Susi",
                'name'   => "As-Susi 'an Abu Amr",
            ],
            [
                'imam'   => "Ibn Kathir",
                'riwaya' => "Al-Bazzi",
                'name'   => "Al-Bazzi 'an Ibn Kathir",
            ],
            [
                'imam'   => "Ibn Kathir",
                'riwaya' => "Qunbul",
                'name'   => "Qunbul 'an Ibn Kathir",
            ],
        ];

        foreach ($rows as $row) {
            // No unique constraints in your migration, so we guard against duplicates here.
            DB::table('qiraat_readings')->updateOrInsert(
                ['imam' => $row['imam'], 'riwaya' => $row['riwaya']],
                ['name' => $row['name']]
            );
        }
    }
}
