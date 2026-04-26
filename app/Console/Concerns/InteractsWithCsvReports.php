<?php

namespace App\Console\Concerns;

trait InteractsWithCsvReports
{
    protected function openCsvReport(?string $path, string $defaultFilename, array $header): void
    {
        $dir = storage_path('app/qiraat_import_logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $this->reportPath = $path ?: ($dir . '/' . $defaultFilename);

        $fp = @fopen($this->reportPath, 'w');
        if (!$fp) {
            $this->warn("Could not open report file: {$this->reportPath}");
            $this->reportFp = null;
            return;
        }

        $this->reportFp = $fp;
        $this->reportLines = 0;

        fputcsv($this->reportFp, $header);
    }

    protected function closeCsvReport(): void
    {
        if ($this->reportFp) {
            fclose($this->reportFp);
            $this->reportFp = null;
        }
    }

    protected function writeCsvReportRow(array $row): void
    {
        if (!$this->reportFp) {
            return;
        }

        if ($this->reportLines >= $this->reportLimit) {
            return;
        }

        fputcsv($this->reportFp, $row);
        $this->reportLines++;
    }
}
