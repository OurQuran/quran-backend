<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qiraat_readings', function (Blueprint $table) {
            $table->string('code', 64)->nullable();
        });

        $map = [
            ['code' => 'nafi_warsh', 'imam_en' => 'Nafi', 'riwaya_en' => 'Warsh'],
            ['code' => 'nafi_qalun', 'imam_en' => 'Nafi', 'riwaya_en' => 'Qalun'],
            ['code' => 'ibn_kathir_bazzi', 'imam_en' => 'Ibn Kathir', 'riwaya_en' => 'Al-Bazzi'],
            ['code' => 'ibn_kathir_qunbul', 'imam_en' => 'Ibn Kathir', 'riwaya_en' => 'Qunbul'],
            ['code' => 'abu_amr_duri', 'imam_en' => 'Abu Amr', 'riwaya_en' => 'Ad-Duri'],
            ['code' => 'abu_amr_susi', 'imam_en' => 'Abu Amr', 'riwaya_en' => 'As-Susi'],
            ['code' => 'ibn_amir_hisham', 'imam_en' => 'Ibn Amir', 'riwaya_en' => 'Hisham'],
            ['code' => 'ibn_amir_ibn_dhakwan', 'imam_en' => 'Ibn Amir', 'riwaya_en' => 'Ibn Dhakwan'],
            ['code' => 'asim_hafs', 'imam_en' => 'Asim', 'riwaya_en' => 'Hafs'],
            ['code' => 'asim_shubah', 'imam_en' => 'Asim', 'riwaya_en' => "Shu'bah"],
            ['code' => 'hamzah_khalaf', 'imam_en' => 'Hamzah', 'riwaya_en' => 'Khalaf'],
            ['code' => 'hamzah_khallad', 'imam_en' => 'Hamzah', 'riwaya_en' => 'Khallad'],
            ['code' => 'kisai_abu_al_harith', 'imam_en' => 'Al-Kisai', 'riwaya_en' => 'Abu Al-Harith'],
            ['code' => 'kisai_duri', 'imam_en' => 'Al-Kisai', 'riwaya_en' => 'Ad-Duri'],
            ['code' => 'abu_jafar_ibn_wardan', 'imam_en' => "Abu Ja'far", 'riwaya_en' => 'Ibn Wardan'],
            ['code' => 'abu_jafar_ibn_jammaz', 'imam_en' => "Abu Ja'far", 'riwaya_en' => 'Ibn Jammaz'],
            ['code' => 'yaqub_ruways', 'imam_en' => "Ya'qub", 'riwaya_en' => 'Ruways'],
            ['code' => 'yaqub_ruh', 'imam_en' => "Ya'qub", 'riwaya_en' => 'Ruh'],
            ['code' => 'khalaf_al_ashir_ishaq', 'imam_en' => "Khalaf al-'Ashir", 'riwaya_en' => 'Ishaq'],
            ['code' => 'khalaf_al_ashir_idris', 'imam_en' => "Khalaf al-'Ashir", 'riwaya_en' => 'Idris'],
        ];

        foreach ($map as $item) {
            DB::table('qiraat_readings')
                ->where('imam->en', $item['imam_en'])
                ->where('riwaya->en', $item['riwaya_en'])
                ->update(['code' => $item['code']]);
        }

        DB::table('qiraat_readings')
            ->whereNull('code')
            ->orderBy('id')
            ->get(['id'])
            ->each(function ($row) {
                DB::table('qiraat_readings')
                    ->where('id', $row->id)
                    ->update(['code' => 'legacy_' . $row->id]);
            });

        DB::statement('ALTER TABLE qiraat_readings ALTER COLUMN code SET NOT NULL');

        Schema::table('qiraat_readings', function (Blueprint $table) {
            $table->unique('code', 'qiraat_readings_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('qiraat_readings', function (Blueprint $table) {
            $table->dropUnique('qiraat_readings_code_unique');
            $table->dropColumn('code');
        });
    }
};
