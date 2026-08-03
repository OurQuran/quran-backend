<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class QiraatImportMaps
{
    public const DEFAULT_XML_DATASET_ROOT = '/home/nightcore/Work/quran-data-kfgqpc';
    public const BASE_READING_CODE = 'asim_hafs';

    public static function readingDefinitions(): array
    {
        return [
            [
                'code' => 'nafi_warsh',
                'imam' => ['en' => 'Nafi', 'ar' => 'نافع', 'ku' => 'نافیع'],
                'riwaya' => ['en' => 'Warsh', 'ar' => 'ورش', 'ku' => 'وەرش'],
            ],
            [
                'code' => 'nafi_qalun',
                'imam' => ['en' => 'Nafi', 'ar' => 'نافع', 'ku' => 'نافیع'],
                'riwaya' => ['en' => 'Qalun', 'ar' => 'قالون', 'ku' => 'قالوون'],
            ],
            [
                'code' => 'ibn_kathir_bazzi',
                'imam' => ['en' => 'Ibn Kathir', 'ar' => 'ابن كثير', 'ku' => 'ئیبن کەسیر'],
                'riwaya' => ['en' => 'Al-Bazzi', 'ar' => 'البزي', 'ku' => 'بەزی'],
            ],
            [
                'code' => 'ibn_kathir_qunbul',
                'imam' => ['en' => 'Ibn Kathir', 'ar' => 'ابن كثير', 'ku' => 'ئیبن کەسیر'],
                'riwaya' => ['en' => 'Qunbul', 'ar' => 'قنبل', 'ku' => 'قونبول'],
            ],
            [
                'code' => 'abu_amr_duri',
                'imam' => ['en' => 'Abu Amr', 'ar' => 'أبو عمرو', 'ku' => 'ئەبو عەمر'],
                'riwaya' => ['en' => 'Ad-Duri', 'ar' => 'الدوري', 'ku' => 'دووری'],
            ],
            [
                'code' => 'abu_amr_susi',
                'imam' => ['en' => 'Abu Amr', 'ar' => 'أبو عمرو', 'ku' => 'ئەبو عەمر'],
                'riwaya' => ['en' => 'As-Susi', 'ar' => 'السوسي', 'ku' => 'سووسی'],
            ],
            [
                'code' => 'ibn_amir_hisham',
                'imam' => ['en' => 'Ibn Amir', 'ar' => 'ابن عامر', 'ku' => 'ئیبن عامیر'],
                'riwaya' => ['en' => 'Hisham', 'ar' => 'هشام', 'ku' => 'هیشام'],
            ],
            [
                'code' => 'ibn_amir_ibn_dhakwan',
                'imam' => ['en' => 'Ibn Amir', 'ar' => 'ابن عامر', 'ku' => 'ئیبن عامیر'],
                'riwaya' => ['en' => 'Ibn Dhakwan', 'ar' => 'ابن ذكوان', 'ku' => 'ئیبن زەکوان'],
            ],
            [
                'code' => self::BASE_READING_CODE,
                'imam' => ['en' => 'Asim', 'ar' => 'عاصم', 'ku' => 'عاسم'],
                'riwaya' => ['en' => 'Hafs', 'ar' => 'حفص', 'ku' => 'حەفس'],
            ],
            [
                'code' => 'asim_shubah',
                'imam' => ['en' => 'Asim', 'ar' => 'عاصم', 'ku' => 'عاسم'],
                'riwaya' => ['en' => "Shu'bah", 'ar' => 'شعبة', 'ku' => 'شوعبە'],
            ],
            [
                'code' => 'hamzah_khalaf',
                'imam' => ['en' => 'Hamzah', 'ar' => 'حمزة', 'ku' => 'حەمزە'],
                'riwaya' => ['en' => 'Khalaf', 'ar' => 'خلف', 'ku' => 'خەلەف'],
            ],
            [
                'code' => 'hamzah_khallad',
                'imam' => ['en' => 'Hamzah', 'ar' => 'حمزة', 'ku' => 'حەمزە'],
                'riwaya' => ['en' => 'Khallad', 'ar' => 'خلاد', 'ku' => 'خەلاد'],
            ],
            [
                'code' => 'kisai_abu_al_harith',
                'imam' => ['en' => 'Al-Kisai', 'ar' => 'الكسائي', 'ku' => 'کیسائی'],
                'riwaya' => ['en' => 'Abu Al-Harith', 'ar' => 'أبو الحارث', 'ku' => 'ئەبو حارس'],
            ],
            [
                'code' => 'kisai_duri',
                'imam' => ['en' => 'Al-Kisai', 'ar' => 'الكسائي', 'ku' => 'کیسائی'],
                'riwaya' => ['en' => 'Ad-Duri', 'ar' => 'الدوري', 'ku' => 'دووری'],
            ],
            [
                'code' => 'abu_jafar_ibn_wardan',
                'imam' => ['en' => "Abu Ja'far", 'ar' => 'أبو جعفر', 'ku' => 'ئەبو جەعفەر'],
                'riwaya' => ['en' => 'Ibn Wardan', 'ar' => 'ابن وردان', 'ku' => 'ئیبن وەردان'],
            ],
            [
                'code' => 'abu_jafar_ibn_jammaz',
                'imam' => ['en' => "Abu Ja'far", 'ar' => 'أبو جعفر', 'ku' => 'ئەبو جەعفەر'],
                'riwaya' => ['en' => 'Ibn Jammaz', 'ar' => 'ابن جماز', 'ku' => 'ئیبن جەمماز'],
            ],
            [
                'code' => 'yaqub_ruways',
                'imam' => ['en' => "Ya'qub", 'ar' => 'يعقوب', 'ku' => 'یەعقووب'],
                'riwaya' => ['en' => 'Ruways', 'ar' => 'رويس', 'ku' => 'ڕوەیس'],
            ],
            [
                'code' => 'yaqub_ruh',
                'imam' => ['en' => "Ya'qub", 'ar' => 'يعقوب', 'ku' => 'یەعقووب'],
                'riwaya' => ['en' => 'Ruh', 'ar' => 'روح', 'ku' => 'ڕووح'],
            ],
            [
                'code' => 'khalaf_al_ashir_ishaq',
                'imam' => ['en' => "Khalaf al-'Ashir", 'ar' => 'خلف العاشر', 'ku' => 'خەلەفی دەیەم'],
                'riwaya' => ['en' => 'Ishaq', 'ar' => 'إسحاق', 'ku' => 'ئیسحاق'],
            ],
            [
                'code' => 'khalaf_al_ashir_idris',
                'imam' => ['en' => "Khalaf al-'Ashir", 'ar' => 'خلف العاشر', 'ku' => 'خەلەفی دەیەم'],
                'riwaya' => ['en' => 'Idris', 'ar' => 'إدريس', 'ku' => 'ئیدریس'],
            ],
        ];
    }

    public static function readingDefinitionByCode(string $code): ?array
    {
        foreach (self::readingDefinitions() as $definition) {
            if ($definition['code'] === $code) {
                return $definition;
            }
        }

        return null;
    }

    public static function seedableQiraatReadings(): array
    {
        return array_map(function (array $definition): array {
            $imam = $definition['imam'];
            $riwaya = $definition['riwaya'];

            return [
                'code' => $definition['code'],
                'imam' => $imam,
                'riwaya' => $riwaya,
                'name' => [
                    'en' => "{$riwaya['en']} 'an {$imam['en']}",
                    'ar' => "{$riwaya['ar']} عن {$imam['ar']}",
                    'ku' => "{$riwaya['ku']} لە {$imam['ku']}ـەوە",
                ],
            ];
        }, self::readingDefinitions());
    }

    public static function xmlByReadingCode(): array
    {
        return [
            'nafi_warsh' => 'warsh/data/warshData_v10.xml',
            'asim_shubah' => 'shouba/data/ShoubaData08.xml',
            'nafi_qalun' => 'qaloon/data/QaloonData_v10.xml',
            'abu_amr_duri' => 'doori/data/DooriData_v09.xml',
            'abu_amr_susi' => 'soosi/data/SoosiData09.xml',
            'ibn_kathir_bazzi' => 'bazzi/data/BazziData_v07.xml',
            'ibn_kathir_qunbul' => 'qumbul/data/QumbulData_v07.xml',
        ];
    }

    public static function mushafExcelByReadingCode(): array
    {
        return [
            'ibn_amir_hisham' => 'qiraat_excels/1_hisham.xlsx',
            'ibn_amir_ibn_dhakwan' => 'qiraat_excels/1_thakwan.xlsx',
            'hamzah_khalaf' => 'qiraat_excels/8_xalaf.xlsx',
            'hamzah_khallad' => 'qiraat_excels/8_xallad.xlsx',
            'kisai_abu_al_harith' => 'qiraat_excels/7_haris.xlsx',
            'kisai_duri' => 'qiraat_excels/7_doori.xlsx',
            'abu_jafar_ibn_wardan' => 'qiraat_excels/6_wardan.xlsx',
            'abu_jafar_ibn_jammaz' => 'qiraat_excels/6_jamar.xlsx',
            'yaqub_ruways' => 'qiraat_excels/11_rwais.xlsx',
            'yaqub_ruh' => 'qiraat_excels/11_rawh.xlsx',
            'khalaf_al_ashir_ishaq' => 'qiraat_excels/9_ishaq.xlsx',
            'khalaf_al_ashir_idris' => 'qiraat_excels/9_idris.xlsx',
        ];
    }

    public static function differenceExcelByReadingCode(): array
    {
        return [
            'nafi_warsh' => 'excels/5_warsh.xlsx',
            'asim_shubah' => 'excels/10_shouba.xlsx',
            'nafi_qalun' => 'excels/4_qaloon.xlsx',
            'abu_amr_duri' => 'excels/3_doori.xlsx',
            'abu_amr_susi' => 'excels/3_soosi.xlsx',
            'ibn_kathir_bazzi' => 'excels/2_bazzi.xlsx',
            'ibn_kathir_qunbul' => 'excels/2_qumbul.xlsx',
            'ibn_amir_hisham' => 'excels/1_hisham.xlsx',
            'ibn_amir_ibn_dhakwan' => 'excels/1_thakwan.xlsx',
            'hamzah_khalaf' => 'excels/8_xalaf.xlsx',
            'hamzah_khallad' => 'excels/8_xallad.xlsx',
            'kisai_abu_al_harith' => 'excels/7_haris.xlsx',
            'kisai_duri' => 'excels/7_doori.xlsx',
            'abu_jafar_ibn_wardan' => 'excels/6_wardan.xlsx',
            'abu_jafar_ibn_jammaz' => 'excels/6_jamar.xlsx',
            'yaqub_ruways' => 'excels/11_rwais.xlsx',
            'yaqub_ruh' => 'excels/11_rawh.xlsx',
            'khalaf_al_ashir_ishaq' => 'excels/9_ishaq.xlsx',
            'khalaf_al_ashir_idris' => 'excels/9_idris.xlsx',
        ];
    }

    public static function nonBaseReadingCodes(): array
    {
        return array_values(array_filter(
            array_map(fn (array $definition) => $definition['code'], self::readingDefinitions()),
            fn (string $code) => $code !== self::BASE_READING_CODE
        ));
    }

    public static function resolveReadingId(string|int|null $reading): ?int
    {
        if ($reading === null || $reading === '') {
            return null;
        }

        if (is_int($reading) || ctype_digit((string) $reading)) {
            $id = (int) $reading;
            return DB::table('qiraat_readings')->where('id', $id)->exists() ? $id : null;
        }

        return self::resolveReadingIdByCode((string) $reading);
    }

    public static function resolveReadingIdByCode(string $code): ?int
    {
        try {
            $row = DB::table('qiraat_readings')->where('code', $code)->first(['id']);
            if ($row) {
                return (int) $row->id;
            }
        } catch (\Throwable) {
            // Fall back to imam/riwaya matching for deployments where the code column is not migrated yet.
        }

        $definition = self::readingDefinitionByCode($code);
        if (!$definition) {
            return null;
        }

        $fallback = DB::table('qiraat_readings')
            ->where('imam->en', $definition['imam']['en'])
            ->where('riwaya->en', $definition['riwaya']['en'])
            ->first(['id']);

        return $fallback ? (int) $fallback->id : null;
    }

    public static function resolveReadingIdsForCodes(array $codes): array
    {
        $resolved = [];

        foreach ($codes as $code) {
            $id = self::resolveReadingIdByCode($code);
            if ($id !== null) {
                $resolved[$code] = $id;
            }
        }

        return $resolved;
    }

    public static function baseReadingId(): int
    {
        return self::resolveReadingIdByCode(self::BASE_READING_CODE) ?? 1;
    }

    public static function usesBaseAyahs(int $qiraatReadingId): bool
    {
        return $qiraatReadingId === self::baseReadingId();
    }

    public static function seededBooks(): array
    {
        $authorName = 'د. احمد ضیف اللە عمر أبو سمهدانە';
        $supervisorName = 'شیح نظام الدین یونس عزیز';

        return [
            [
                'name' => 'القراءات - ابن عامر الشامي (هشام وابن ذكوان)',
                'authors' => [$authorName],
                'supervisors' => [$supervisorName],
                'pdf_path' => 'books/05 كتاب ابن عامر -النسخة الكردية.pdf',
            ],
            [
                'name' => 'القراءات - ابن كثير المكي (البزي وقنبل)',
                'authors' => [$authorName],
                'supervisors' => [$supervisorName],
                'pdf_path' => 'books/01 كتاب ابن كثير -النسخة الكردية.pdf',
            ],
            [
                'name' => 'القراءات - أبو عمرو البصري (الدوري والسوسي)',
                'authors' => [$authorName],
                'supervisors' => [$supervisorName],
                'pdf_path' => 'books/02 كتاب ابو عمرو البصري-النسخة الكردية-3.pdf',
            ],
            [
                'name' => 'رواية قالون عن نافع',
                'authors' => [$authorName],
                'supervisors' => [$supervisorName],
                'pdf_path' => 'books/03 كتاب قالون-النسخة الكردية.pdf',
            ],
            [
                'name' => 'رواية ورش عن نافع',
                'authors' => [$authorName],
                'supervisors' => [$supervisorName],
                'pdf_path' => 'books/04 كتاب ورش -النسخة الكردية.pdf',
            ],
            [
                'name' => 'القراءات - أبو جعفر المدني (ابن وردان وابن جماز)',
                'authors' => [$authorName],
                'supervisors' => [$supervisorName],
                'pdf_path' => 'books/09 كتاب ابو جعفر-النسخة الكردية.pdf',
            ],
            [
                'name' => 'القراءات - الكسائي الكوفي (أبو الحارث والدوري)',
                'authors' => [$authorName],
                'supervisors' => [$supervisorName],
                'pdf_path' => 'books/08 كتاب الكسائي -النسخة الكردية.pdf',
            ],
            [
                'name' => 'القراءات - حمزة الزيات (خلف وخلاد)',
                'authors' => [$authorName],
                'supervisors' => [$supervisorName],
                'pdf_path' => 'books/07 كتاب حمزة-النسخة الكردية.pdf',
            ],
            [
                'name' => 'القراءات - خلف العاشر (إسحاق وإدريس)',
                'authors' => [$authorName],
                'supervisors' => [$supervisorName],
                'pdf_path' => 'books/11 كتاب خلف العاشر -النسخة الكردية.pdf',
            ],
            [
                'name' => 'رواية شعبة عن عاصم',
                'authors' => [$authorName],
                'supervisors' => [$supervisorName],
                'pdf_path' => 'books/06 كتاب شعبة -النسخة الكردية.pdf',
            ],
            [
                'name' => 'القراءات - يعقوب الحضرمي (رويس وروح)',
                'authors' => [$authorName],
                'supervisors' => [$supervisorName],
                'pdf_path' => 'books/10 كتاب يعقوب الحضرمي-النسخة الكردية.pdf',
            ],
        ];
    }

    public static function booksByFilenameKeyword(): array
    {
        return [
            'ابن عامر' => 'القراءات - ابن عامر الشامي (هشام وابن ذكوان)',
            'ئیبن عامیر' => 'القراءات - ابن عامر الشامي (هشام وابن ذكوان)',
            'ئیبن عامیری' => 'القراءات - ابن عامر الشامي (هشام وابن ذكوان)',
            'ابن كثير' => 'القراءات - ابن كثير المكي (البزي وقنبل)',
            'ئیبن كەثير' => 'القراءات - ابن كثير المكي (البزي وقنبل)',
            'ئیبن کەثير' => 'القراءات - ابن كثير المكي (البزي وقنبل)',
            'ابو عمرو' => 'القراءات - أبو عمرو البصري (الدوري والسوسي)',
            'أبو عمرو' => 'القراءات - أبو عمرو البصري (الدوري والسوسي)',
            'ئەبوعەمر' => 'القراءات - أبو عمرو البصري (الدوري والسوسي)',
            'ئەبوعەمری' => 'القراءات - أبو عمرو البصري (الدوري والسوسي)',
            'قالون' => 'رواية قالون عن نافع',
            'ورش' => 'رواية ورش عن نافع',
            'وەڕش' => 'رواية ورش عن نافع',
            'ابو جعفر' => 'القراءات - أبو جعفر المدني (ابن وردان وابن جماز)',
            'أبو جعفر' => 'القراءات - أبو جعفر المدني (ابن وردان وابن جماز)',
            'ئەبو جەعفەر' => 'القراءات - أبو جعفر المدني (ابن وردان وابن جماز)',
            'كسائي' => 'القراءات - الكسائي الكوفي (أبو الحارث والدوري)',
            'الكسائي' => 'القراءات - الكسائي الكوفي (أبو الحارث والدوري)',
            'كیسائى' => 'القراءات - الكسائي الكوفي (أبو الحارث والدوري)',
            'کیسائى' => 'القراءات - الكسائي الكوفي (أبو الحارث والدوري)',
            'حمزة' => 'القراءات - حمزة الزيات (خلف وخلاد)',
            'حەمزە' => 'القراءات - حمزة الزيات (خلف وخلاد)',
            'خلف العاشر' => 'القراءات - خلف العاشر (إسحاق وإدريس)',
            'خەلەف' => 'القراءات - خلف العاشر (إسحاق وإدريس)',
            'شعبة' => 'رواية شعبة عن عاصم',
            'شوعبە' => 'رواية شعبة عن عاصم',
            'يعقوب' => 'القراءات - يعقوب الحضرمي (رويس وروح)',
            'یەعقوب' => 'القراءات - يعقوب الحضرمي (رويس وروح)',
            'یەعقوبی' => 'القراءات - يعقوب الحضرمي (رويس وروح)',
        ];
    }
}
