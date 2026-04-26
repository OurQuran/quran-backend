<?php

namespace App\Console\Commands\Qiraat;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearQiraatDifferences extends Command
{
    protected $signature = 'qiraat:clear-differences
        {--force : Skip confirmation}
    ';

    protected $description = 'Truncate qiraat_differences so you can re-run the import.';

    public function handle(): int
    {
        if (!$this->option('force') && !$this->confirm('Clear all rows in qiraat_differences?')) {
            return self::SUCCESS;
        }

        $count = DB::table('qiraat_differences')->count();
        DB::table('qiraat_differences')->truncate();
        $this->info("Cleared qiraat_differences ({$count} rows). You can re-run qiraat:import-differences-excel.");

        return self::SUCCESS;
    }
}
