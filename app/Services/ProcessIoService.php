<?php

namespace App\Services;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class ProcessIoService
{
    /**
     * Sample per-process disk IO via privileged `diagnose-pidstat` / `pidstat -d`.
     *
     * @return array{ok: bool, command: string|null, interval_sec: int, processes: list<array<string, mixed>>, error: string|null}
     */
    public function sample(int $interval = 1, int $limit = 15): array
    {
        $interval = max(1, min(5, $interval));
        $limit = max(5, min(40, $limit));
        [$argv, $label] = $this->buildCommand($interval);

        $process = new Process($argv);
        $process->setTimeout($interval + 8);
        $process->run();

        return $this->finishSampleProcess($process, $interval, $limit, $label);
    }

    /**
     * Start a non-blocking pidstat sample (for parallel use with iostat).
     */
    public function startSampleProcess(int $interval = 1): Process
    {
        $interval = max(1, min(5, $interval));
        [$argv] = $this->buildCommand($interval);

        $process = new Process($argv);
        $process->setTimeout($interval + 8);
        $process->start();

        return $process;
    }

    /**
     * Finish a Process started by startSampleProcess().
     *
     * @return array{ok: bool, command: string|null, interval_sec: int, processes: list<array<string, mixed>>, error: string|null}
     */
    public function finishSampleProcess(Process $process, int $interval = 1, int $limit = 15, ?string $commandLabel = null): array
    {
        $interval = max(1, min(5, $interval));
        $limit = max(5, min(40, $limit));

        if ($commandLabel === null) {
            [, $commandLabel] = $this->buildCommand($interval);
        }

        if (! $process->isTerminated()) {
            $process->wait();
        }

        if (! $process->isSuccessful()) {
            $hint = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            return [
                'ok' => false,
                'command' => $commandLabel,
                'interval_sec' => $interval,
                'processes' => [],
                'error' => $hint !== '' ? $hint : 'pidstat exited with code '.$process->getExitCode(),
            ];
        }

        $stdout = trim($process->getOutput());
        if ($stdout === '') {
            return [
                'ok' => false,
                'command' => $commandLabel,
                'interval_sec' => $interval,
                'processes' => [],
                'error' => 'Empty pidstat output.',
            ];
        }

        try {
            $rows = $this->parseOutput($stdout);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'command' => $commandLabel,
                'interval_sec' => $interval,
                'processes' => [],
                'error' => 'Parse error: '.$e->getMessage(),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $tb = ($b['kB_rd_per_s'] ?? 0) + ($b['kB_wr_per_s'] ?? 0);
            $ta = ($a['kB_rd_per_s'] ?? 0) + ($a['kB_wr_per_s'] ?? 0);

            return $tb <=> $ta;
        });

        $rows = array_slice($rows, 0, $limit);

        // Successful run but zero rows usually means the caller lacked permission to read /proc/*/io.
        if ($rows === [] && ! $this->commandIsPrivileged($commandLabel)) {
            return [
                'ok' => false,
                'command' => $commandLabel,
                'interval_sec' => $interval,
                'processes' => [],
                'error' => 'No process IO visible (need sudo wrapper /usr/local/sbin/diagnose-pidstat).',
            ];
        }

        return [
            'ok' => true,
            'command' => $commandLabel,
            'interval_sec' => $interval,
            'processes' => $rows,
            'error' => null,
        ];
    }

    /**
     * @return array{0: list<string>, 1: string}
     */
    private function buildCommand(int $interval): array
    {
        $wrapper = trim((string) config('monitor.pidstat_sudo_wrapper', '/usr/local/sbin/diagnose-pidstat'));
        $sudo = trim((string) (config('monitor.sudo_binary') ?: ''));
        if ($sudo === '') {
            $sudo = (new ExecutableFinder())->find('sudo', '/usr/bin/sudo', ['/usr/sbin', '/usr/bin', '/bin', '/sbin'])
                ?? '/usr/bin/sudo';
        }

        // Prefer the scoped sudo wrapper (PHP-FPM www cannot read other users' /proc/*/io).
        // Do not use is_file(): open_basedir often hides /usr/local/sbin from PHP.
        if ($wrapper !== '' && (function_exists('posix_geteuid') ? posix_geteuid() !== 0 : true)) {
            return [
                [$sudo, '-n', $wrapper, (string) $interval],
                'sudo -n '.$wrapper.' '.$interval,
            ];
        }

        $binaryConfig = config('monitor.pidstat_binary');
        $binary = is_string($binaryConfig) && $binaryConfig !== '' ? $binaryConfig : null;

        if ($binary === null) {
            $binary = (new ExecutableFinder())->find('pidstat', '/usr/bin/pidstat', ['/usr/sbin', '/usr/bin', '/bin', '/sbin'])
                ?? '/usr/bin/pidstat';
        }

        return [
            [$binary, '-d', (string) $interval, '1'],
            'pidstat -d '.$interval.' 1',
        ];
    }

    private function commandIsPrivileged(string $commandLabel): bool
    {
        return str_contains($commandLabel, 'sudo') || str_contains($commandLabel, 'diagnose-pidstat');
    }

    /**
     * @return list<array{pid: int, uid: int|null, command: string, service: string, kB_rd_per_s: float, kB_wr_per_s: float, kB_ccwr_per_s: float, iodelay: float, total_kB_per_s: float}>
     */
    private function parseOutput(string $out): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($out)) ?: [];
        $rows = [];
        $seenPids = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, 'Linux') || str_contains($line, 'kB_rd/s')) {
                continue;
            }

            // Prefer Average: lines (one row per PID for the sample window).
            $isAverage = str_starts_with($line, 'Average:');
            if (! $isAverage) {
                // Fall back to timestamped sample lines if Average block missing.
                if (! preg_match('/^\d{1,2}:\d{2}:\d{2}/', $line)) {
                    continue;
                }
            }

            $parts = preg_split('/\s+/', $line);
            if ($parts === false || count($parts) < 8) {
                continue;
            }

            // Average: UID PID kB_rd/s kB_wr/s kB_ccwr/s iodelay Command...
            // HH:MM:SS AM/PM UID PID ...  OR  HH:MM:SS UID PID ...
            $offset = 0;
            if ($isAverage) {
                $offset = 1; // skip "Average:"
            } else {
                // Skip time token(s); AM/PM locale may add an extra field.
                $offset = 1;
                if (isset($parts[1]) && preg_match('/^(AM|PM)$/i', $parts[1])) {
                    $offset = 2;
                }
            }

            if (! isset($parts[$offset + 6])) {
                continue;
            }

            $uid = is_numeric($parts[$offset]) ? (int) $parts[$offset] : null;
            $pid = (int) $parts[$offset + 1];
            if ($pid <= 0 || isset($seenPids[$pid])) {
                continue;
            }

            $rd = (float) $parts[$offset + 2];
            $wr = (float) $parts[$offset + 3];
            $ccwr = (float) $parts[$offset + 4];
            $iodelay = (float) $parts[$offset + 5];
            $command = implode(' ', array_slice($parts, $offset + 6));

            if ($rd <= 0 && $wr <= 0 && $iodelay <= 0) {
                continue;
            }

            $seenPids[$pid] = true;
            $rows[] = [
                'pid' => $pid,
                'uid' => $uid,
                'command' => $command,
                'service' => $this->serviceLabel($command),
                'kB_rd_per_s' => round($rd, 2),
                'kB_wr_per_s' => round($wr, 2),
                'kB_ccwr_per_s' => round($ccwr, 2),
                'iodelay' => round($iodelay, 2),
                'total_kB_per_s' => round($rd + $wr, 2),
            ];
        }

        return $rows;
    }

    private function serviceLabel(string $command): string
    {
        $c = strtolower($command);

        return match (true) {
            str_contains($c, 'mysqld') => 'MySQL',
            str_contains($c, 'mariadbd') => 'MariaDB',
            str_contains($c, 'freeradius') || $c === 'radiusd' => 'FreeRADIUS',
            str_contains($c, 'php-fpm') => 'PHP-FPM',
            str_contains($c, 'httpd') || str_contains($c, 'apache') => 'Apache/httpd',
            str_contains($c, 'nginx') => 'Nginx',
            str_contains($c, 'rsyslog') => 'rsyslog',
            str_contains($c, 'systemd-journal') => 'journald',
            str_contains($c, 'jbd2/') => 'ext4 journal',
            str_contains($c, 'kworker') => 'kernel worker',
            str_contains($c, 'fail2ban') => 'fail2ban',
            str_contains($c, 'redis') => 'Redis',
            default => $command,
        };
    }
}
