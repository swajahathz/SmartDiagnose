<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MySqlLogTailService
{
    public function tailErrorLog(int $lines): array
    {
        if (! config('monitor.log_tail_enabled', true)) {
            return ['enabled' => false, 'path' => null, 'lines' => [], 'error' => null];
        }

        try {
            $row = DB::connection('monitor')->selectOne("SHOW VARIABLES LIKE 'log_error'");
        } catch (\Throwable $e) {
            return ['enabled' => true, 'path' => null, 'lines' => [], 'error' => $e->getMessage()];
        }

        $path = $row->Value ?? null;
        if (! is_string($path) || $path === '' || $path === 'stderr') {
            return [
                'enabled' => true,
                'path' => $path,
                'lines' => [],
                'error' => $path === 'stderr' ? 'log_error is stderr (tail not supported here)' : 'log_error not set',
            ];
        }

        if (! is_readable($path)) {
            return [
                'enabled' => true,
                'path' => $path,
                'lines' => [],
                'error' => 'File not readable by PHP (check user / permissions)',
            ];
        }

        $content = @file($path, FILE_IGNORE_NEW_LINES);
        if ($content === false) {
            return ['enabled' => true, 'path' => $path, 'lines' => [], 'error' => 'Could not read file'];
        }

        $slice = array_slice($content, max(0, count($content) - $lines));

        return [
            'enabled' => true,
            'path' => $path,
            'lines' => $slice,
            'error' => null,
        ];
    }
}
