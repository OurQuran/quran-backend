<?php

namespace App\Console\Concerns;

trait InteractsWithImportPaths
{
    protected function resolveStorageMappedPath(?string $argumentPath, array $map, int $key): string
    {
        $argumentPath = is_string($argumentPath) ? trim($argumentPath) : '';

        if ($argumentPath !== '' && strtolower($argumentPath) !== 'auto') {
            return $this->expandPath($argumentPath);
        }

        $relativePath = $map[$key] ?? '';
        if ($relativePath === '') {
            return '';
        }

        if ($this->isAbsolutePath($relativePath)) {
            return $this->expandPath($relativePath);
        }

        return storage_path('app/' . ltrim($relativePath, '/'));
    }

    protected function resolveSpreadsheetFiles(string $pathArg): array
    {
        $pathArg = trim($pathArg);
        $parts = array_filter(array_map('trim', explode(',', $pathArg)));
        $files = [];

        foreach ($parts as $part) {
            $path = $this->expandPath($part);

            if (is_dir($path)) {
                $found = glob(rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.xlsx') ?: [];
                foreach ($found as $file) {
                    $files[] = $file;
                }
                continue;
            }

            if (is_file($path) && str_ends_with(strtolower($path), '.xlsx')) {
                $files[] = $path;
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    protected function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:\\\\/', $path);
    }

    protected function expandPath(string $path): string
    {
        $path = trim($path);

        if ($path !== '' && $path[0] === '~') {
            $home = getenv('HOME') ?: '';
            if ($home !== '') {
                $path = $home . substr($path, 1);
            }
        }

        return $path;
    }
}
