<?php

namespace Database\Seeders;

use App\Support\QiraatImportMaps;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QiraatReadingsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = QiraatImportMaps::seedableQiraatReadings();

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $exists = DB::table('qiraat_readings')
                    ->where('code', $row['code'])
                    ->first();

                if (!$exists) {
                    $exists = DB::table('qiraat_readings')
                        ->where('imam->en', $row['imam']['en'])
                        ->where('riwaya->en', $row['riwaya']['en'])
                        ->first();
                }

                $data = [
                    'code'   => $row['code'],
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
}
