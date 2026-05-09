<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class FreeradiusLogTailService
{
    /**
     * Last N lines from the configured FreeRADIUS log file.
     *
     * `freeradius -X` writes to the terminal; point this to a file you capture
     * (e.g. redirect or `log { }` in your FreeRADIUS config).
     *
     * @return array{enabled: bool, path: ?string, lines: list<string>, error: ?string}
     */
    public function tail(): array
    {
        if (! config('monitor.freeradius_log_enabled', true)) {
            return [
                'enabled' => false,
                'path' => null,
                'lines' => [],
                'error' => null,
            ];
        }

        $path = config('monitor.freeradius_log_path');
        if (! is_string($path) || $path === '') {
            return [
                'enabled' => true,
                'path' => null,
                'lines' => [],
                'error' => 'Set FREERADIUS_LOG_PATH in .env to a log file (see .env.example).',
            ];
        }

        $lines = max(20, min(5000, (int) config('monitor.freeradius_log_lines', 300)));

        if (! is_file($path)) {
            return [
                'enabled' => true,
                'path' => $path,
                'lines' => [],
                'error' => 'File does not exist yet or path is wrong. Ensure FreeRADIUS writes here or redirect freeradius -X output.',
            ];
        }

        if (! is_readable($path) && ! function_exists('shell_exec')) {
            return [
                'enabled' => true,
                'path' => $path,
                'lines' => [],
                'error' => 'File not readable by the web user (chmod / group) and shell_exec is unavailable.',
            ];
        }

        if (function_exists('shell_exec')) {
            $out = shell_exec('tail -n '.(int) $lines.' '.escapeshellarg($path).' 2>/dev/null');
            if (is_string($out)) {
                $trim = trim($out);
                $rows = $trim === '' ? [] : preg_split("/\r\n|\n|\r/", $trim, -1, PREG_SPLIT_NO_EMPTY);

                return [
                    'enabled' => true,
                    'path' => $path,
                    'lines' => is_array($rows) ? $rows : [],
                    'error' => null,
                ];
            }
        }

        $process = new Process(['/usr/bin/tail', '-n', (string) $lines, $path]);
        $process->setTimeout(15);
        $process->run();

        if ($process->isSuccessful()) {
            $out = trim($process->getOutput());
            $rows = $out === '' ? [] : preg_split("/\r\n|\n|\r/", $out, -1, PREG_SPLIT_NO_EMPTY);

            return [
                'enabled' => true,
                'path' => $path,
                'lines' => is_array($rows) ? $rows : [],
                'error' => null,
            ];
        }

        $content = @file($path, FILE_IGNORE_NEW_LINES);
        if ($content === false) {
            return [
                'enabled' => true,
                'path' => $path,
                'lines' => [],
                'error' => 'Could not read file: '.trim($process->getErrorOutput() ?: 'unknown'),
            ];
        }

        return [
            'enabled' => true,
            'path' => $path,
            'lines' => array_slice($content, max(0, count($content) - $lines)),
            'error' => null,
        ];
    }
}
