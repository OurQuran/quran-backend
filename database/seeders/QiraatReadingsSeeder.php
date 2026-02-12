<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QiraatReadingsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // 1) Nafi'
            $this->formatRow(['en' => 'Nafi', 'ar' => 'نافع', 'ku' => 'نافیع'], ['en' => 'Warsh', 'ar' => 'ورش', 'ku' => 'وەرش']),
            $this->formatRow(['en' => 'Nafi', 'ar' => 'نافع', 'ku' => 'نافیع'], ['en' => 'Qalun', 'ar' => 'قالون', 'ku' => 'قالوون']),

            // 2) Ibn Kathir
            $this->formatRow(['en' => 'Ibn Kathir', 'ar' => 'ابن كثير', 'ku' => 'ئیبن کەسیر'], ['en' => 'Al-Bazzi', 'ar' => 'البزي', 'ku' => 'بەزی']),
            $this->formatRow(['en' => 'Ibn Kathir', 'ar' => 'ابن كثير', 'ku' => 'ئیبن کەسیر'], ['en' => 'Qunbul', 'ar' => 'قنبل', 'ku' => 'قونبول']),

            // 3) Abu Amr
            $this->formatRow(['en' => 'Abu Amr', 'ar' => 'أبو عمرو', 'ku' => 'ئەبو عەمر'], ['en' => 'Ad-Duri', 'ar' => 'الدوري', 'ku' => 'دووری']),
            $this->formatRow(['en' => 'Abu Amr', 'ar' => 'أبو عمرو', 'ku' => 'ئەبو عەمر'], ['en' => 'As-Susi', 'ar' => 'السوسي', 'ku' => 'سووسی']),

            // 4) Ibn Amir
            $this->formatRow(['en' => 'Ibn Amir', 'ar' => 'ابن عامر', 'ku' => 'ئیبن عامیر'], ['en' => 'Hisham', 'ar' => 'هشام', 'ku' => 'هیشام']),
            $this->formatRow(['en' => 'Ibn Amir', 'ar' => 'ابن عامر', 'ku' => 'ئیبن عامیر'], ['en' => 'Ibn Dhakwan', 'ar' => 'ابن ذكوان', 'ku' => 'ئیبن زەکوان']),

            // 5) Asim
            $this->formatRow(['en' => 'Asim', 'ar' => 'عاصم', 'ku' => 'عاسم'], ['en' => 'Hafs', 'ar' => 'حفص', 'ku' => 'حەفس']),
            $this->formatRow(['en' => 'Asim', 'ar' => 'عاصم', 'ku' => 'عاسم'], ['en' => 'Shu\'bah', 'ar' => 'شعبة', 'ku' => 'شوعبە']),

            // 6) Hamzah
            $this->formatRow(['en' => 'Hamzah', 'ar' => 'حمزة', 'ku' => 'حەمزە'], ['en' => 'Khalaf', 'ar' => 'خلف', 'ku' => 'خەلەف']),
            $this->formatRow(['en' => 'Hamzah', 'ar' => 'حمزة', 'ku' => 'حەمزە'], ['en' => 'Khallad', 'ar' => 'خلاد', 'ku' => 'خەلاد']),

            // 7) Al-Kisai
            $this->formatRow(['en' => 'Al-Kisai', 'ar' => 'الكسائي', 'ku' => 'کیسائی'], ['en' => 'Abu Al-Harith', 'ar' => 'أبو الحارث', 'ku' => 'ئەبو حارس']),
            $this->formatRow(['en' => 'Al-Kisai', 'ar' => 'الكسائي', 'ku' => 'کیسائی'], ['en' => 'Ad-Duri', 'ar' => 'الدوري', 'ku' => 'دووری']),

            // 8) Abu Ja'far
            $this->formatRow(['en' => 'Abu Ja\'far', 'ar' => 'أبو جعفر', 'ku' => 'ئەبو جەعفەر'], ['en' => 'Ibn Wardan', 'ar' => 'ابن وردان', 'ku' => 'ئیبن وەردان']),
            $this->formatRow(['en' => 'Abu Ja\'far', 'ar' => 'أبو جعفر', 'ku' => 'ئەبو جەعفەر'], ['en' => 'Ibn Jammaz', 'ar' => 'ابن جماز', 'ku' => 'ئیبن جەمماز']),

            // 9) Ya'qub
            $this->formatRow(['en' => 'Ya\'qub', 'ar' => 'يعقوب', 'ku' => 'یەعقووب'], ['en' => 'Ruways', 'ar' => 'رويس', 'ku' => 'ڕوەیس']),
            $this->formatRow(['en' => 'Ya\'qub', 'ar' => 'يعقوب', 'ku' => 'یەعقووب'], ['en' => 'Ruh', 'ar' => 'روح', 'ku' => 'ڕووح']),

            // 10) Khalaf al-'Ashir
            $this->formatRow(['en' => 'Khalaf al-\'Ashir', 'ar' => 'خلف العاشر', 'ku' => 'خەلەفی دەیەم'], ['en' => 'Ishaq', 'ar' => 'إسحاق', 'ku' => 'ئیسحاق']),
            $this->formatRow(['en' => 'Khalaf al-\'Ashir', 'ar' => 'خلف العاشر', 'ku' => 'خەلەفی دەیەم'], ['en' => 'Idris', 'ar' => 'إدريس', 'ku' => 'ئیدریس']),
        ];

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                // Check if the record exists by English name
                $exists = DB::table('qiraat_readings')
                    ->where('imam->en', $row['imam']['en'])
                    ->where('riwaya->en', $row['riwaya']['en'])
                    ->first();

                // Encode for DB storage
                $data = [
                    'imam'   => json_encode($row['imam']),
                    'riwaya' => json_encode($row['riwaya']),
                    'name'   => json_encode($row['name']),
                ];

                if ($exists) {
                    DB::table('qiraat_readings')->where('id', $exists->id)->update($data);
                } else {
                    DB::table('qiraat_readings')->insert($data);
                }
            }
        });
    }

    private function formatRow(array $imam, array $riwaya): array
    {
        // Return raw arrays, not JSON strings
        return [
            'imam'   => $imam,
            'riwaya' => $riwaya,
            'name'   => [
                'en' => "{$riwaya['en']} 'an {$imam['en']}",
                'ar' => "{$riwaya['ar']} عن {$imam['ar']}",
                'ku' => "{$riwaya['ku']} لە {$imam['ku']}ـەوە",
            ],
        ];
    }
}
