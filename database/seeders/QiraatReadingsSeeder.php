<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QiraatReadingsSeeder extends Seeder
{
    public function run(): void
    {
        // One-time normalization for your existing data
        DB::table('qiraat_readings')
            ->where('imam', 'Nafi')
            ->where('riwaya', 'Qaloon')
            ->update([
                'riwaya' => 'Qalun',
                'name'   => "Qalun 'an Nafi",
            ]);

        $rows = [
            // 1) Nafi
            ['imam' => "Nafi", 'riwaya' => "Warsh", 'name' => "Warsh 'an Nafi"],
            ['imam' => "Nafi", 'riwaya' => "Qalun", 'name' => "Qalun 'an Nafi"],

            // 2) Ibn Kathir
            ['imam' => "Ibn Kathir", 'riwaya' => "Al-Bazzi", 'name' => "Al-Bazzi 'an Ibn Kathir"],
            ['imam' => "Ibn Kathir", 'riwaya' => "Qunbul",   'name' => "Qunbul 'an Ibn Kathir"],

            // 3) Abu Amr
            ['imam' => "Abu Amr", 'riwaya' => "Ad-Duri", 'name' => "Ad-Duri 'an Abu Amr"],
            ['imam' => "Abu Amr", 'riwaya' => "As-Susi", 'name' => "As-Susi 'an Abu Amr"],

            // 4) Ibn Amir
            ['imam' => "Ibn Amir", 'riwaya' => "Hisham",      'name' => "Hisham 'an Ibn Amir"],
            ['imam' => "Ibn Amir", 'riwaya' => "Ibn Dhakwan", 'name' => "Ibn Dhakwan 'an Ibn Amir"],

            // 5) Asim
            ['imam' => "Asim", 'riwaya' => "Hafs",    'name' => "Hafs 'an Asim"],
            ['imam' => "Asim", 'riwaya' => "Shu'bah", 'name' => "Shu'bah 'an Asim"],

            // 6) Hamzah
            ['imam' => "Hamzah", 'riwaya' => "Khalaf",  'name' => "Khalaf 'an Hamzah"],
            ['imam' => "Hamzah", 'riwaya' => "Khallad", 'name' => "Khallad 'an Hamzah"],

            // 7) Al-Kisai  (NOTE: Ad-Duri appears here too, that's OK because imam differs)
            ['imam' => "Al-Kisai", 'riwaya' => "Abu Al-Harith", 'name' => "Abu Al-Harith 'an Al-Kisai"],
            ['imam' => "Al-Kisai", 'riwaya' => "Ad-Duri",       'name' => "Ad-Duri 'an Al-Kisai"],

            // 8) Abu Ja'far
            ['imam' => "Abu Ja'far", 'riwaya' => "Ibn Wardan", 'name' => "Ibn Wardan 'an Abu Ja'far"],
            ['imam' => "Abu Ja'far", 'riwaya' => "Ibn Jammaz", 'name' => "Ibn Jammaz 'an Abu Ja'far"],

            // 9) Ya'qub
            ['imam' => "Ya'qub", 'riwaya' => "Ruways", 'name' => "Ruways 'an Ya'qub"],
            ['imam' => "Ya'qub", 'riwaya' => "Ruh",    'name' => "Ruh 'an Ya'qub"],

            // 10) Khalaf al-'Ashir (10th imam) - keep the imam name explicit to avoid confusion
            ['imam' => "Khalaf al-'Ashir", 'riwaya' => "Ishaq", 'name' => "Ishaq 'an Khalaf al-'Ashir"],
            ['imam' => "Khalaf al-'Ashir", 'riwaya' => "Idris", 'name' => "Idris 'an Khalaf al-'Ashir"],
        ];

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                DB::table('qiraat_readings')->updateOrInsert(
                    ['imam' => $row['imam'], 'riwaya' => $row['riwaya']],
                    ['name' => $row['name']]
                );
            }
        });
    }
}
