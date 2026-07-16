<?php

namespace App\Services;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class IostatService
{
    public function __construct(
        private readonly ProcessIoService $processIo,
    ) {}

    /**
     * Runs `iostat -y -x {interval} 1` in parallel with `pidstat -d {interval} 1`.
     *
     * @return array<string, mixed>
     */
    public function sample(): array
    {
        $started = microtime(true);
        $interval = max(1, min(60, (int) config('monitor.iostat_interval', 1)));
        $processLimit = max(5, min(40, (int) config('monitor.process_io_limit', 15)));

        $binaryConfig = config('monitor.iostat_binary');
        $binary = is_string($binaryConfig) && $binaryConfig !== '' ? $binaryConfig : null;

        // Do not rely on is_executable(): open_basedir (e.g. .user.ini for this site) makes
        // PHP report false for /usr/bin/* even though proc_open can still run sysstat binaries.
        if ($binary === null) {
            $binary = (new ExecutableFinder())->find('iostat', '/usr/bin/iostat', ['/usr/sbin', '/usr/bin', '/bin', '/sbin'])
                ?? '/usr/bin/iostat';
        }

        $process = new Process([$binary, '-y', '-x', (string) $interval, '1']);
        $process->setTimeout($interval + 8);

        // Sample per-process IO in the same wall-clock window as iostat.
        $pidstatProc = $this->processIo->startSampleProcess($interval);
        $process->start();
        $process->wait();
        $processIo = $this->processIo->finishSampleProcess($pidstatProc, $interval, $processLimit);

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        if (! $process->isSuccessful()) {
            $hint = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            return $this->failure($started, $hint !== '' ? $hint : 'iostat exited with code '.$process->getExitCode(), $processIo);
        }

        $stdout = trim($process->getOutput());
        if ($stdout === '') {
            return $this->failure($started, 'Empty iostat output.', $processIo);
        }

        try {
            $parsed = $this->parseOutput($stdout);
        } catch (\Throwable $e) {
            return $this->failure($started, 'Parse error: '.$e->getMessage(), $processIo);
        }

        $significantDevices = [];
        foreach ($parsed['devices'] as $row) {
            $row['counts_for_peak'] = $this->includeInPeakUi($row['device']);
            $significantDevices[] = $row;
        }

        $peakSubset = array_values(array_filter($significantDevices, static fn ($r) => ($r['counts_for_peak'] ?? false) === true));
        $utilValues = array_map(static fn ($r) => (float) $r['util_percent'], $peakSubset);
        $maxUtil = count($utilValues) > 0 ? max($utilValues) : null;
        $maxDevice = null;
        if ($maxUtil !== null) {
            foreach ($peakSubset as $r) {
                if ((float) $r['util_percent'] === $maxUtil) {
                    $maxDevice = $r['device'];

                    break;
                }
            }
        }

        $meanUtil = count($utilValues) > 0 ? array_sum($utilValues) / count($utilValues) : null;
        $ioWait = isset($parsed['cpu']['iowait']) ? (float) $parsed['cpu']['iowait'] : null;

        $level = $this->rollupLevel($maxUtil ?? 0.0, $ioWait ?? 0.0, count($peakSubset));
        $topProcess = $processIo['processes'][0] ?? null;

        $detail = $this->rollupDetail($level, $maxDevice, $maxUtil, $meanUtil, $ioWait, count($parsed['devices']));
        if (is_array($topProcess) && (($topProcess['total_kB_per_s'] ?? 0) > 0)) {
            $detail .= sprintf(
                ' Top IO: %s (PID %d) R %.0f / W %.0f kB/s.',
                $topProcess['service'] ?? $topProcess['command'] ?? 'process',
                (int) ($topProcess['pid'] ?? 0),
                (float) ($topProcess['kB_rd_per_s'] ?? 0),
                (float) ($topProcess['kB_wr_per_s'] ?? 0),
            );
        }

        return [
            'ok' => true,
            'ts' => now()->toIso8601String(),
            'elapsed_ms' => $elapsedMs,
            'interval_sec' => $interval,
            'command' => 'iostat -y -x '.$interval.' 1',
            'binary' => $binary,
            'cpu' => $parsed['cpu'],
            'devices' => $significantDevices,
            'processes' => $processIo['processes'],
            'process_io' => [
                'ok' => $processIo['ok'],
                'command' => $processIo['command'],
                'error' => $processIo['error'],
            ],
            'summary' => [
                'level' => $level,
                'headline' => $this->rollupHeadline($level, $maxUtil, $ioWait),
                'detail' => $detail,
                'max_util_percent' => $maxUtil,
                'max_util_device' => $maxDevice,
                'mean_util_percent' => $meanUtil !== null ? round($meanUtil, 1) : null,
                'iowait_percent' => $ioWait,
                'device_count_reported' => count($parsed['devices']),
                'device_count_peak' => count($peakSubset),
                'top_io_service' => is_array($topProcess) ? ($topProcess['service'] ?? null) : null,
                'top_io_pid' => is_array($topProcess) ? ($topProcess['pid'] ?? null) : null,
            ],
        ];
    }

    /**
     * @return array{cpu: array<string, float>|null, devices: list<array<string, mixed>>}
     */
    private function parseOutput(string $out): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($out)) ?: [];

        return [
            'cpu' => $this->extractCpu(array_values($lines)),
            'devices' => $this->extractDevices(array_values($lines)),
        ];
    }

    /**
     * @param  list<string>  $lines
     */
    private function extractCpu(array $lines): ?array
    {
        foreach ($lines as $i => $line) {
            if (! str_contains((string) $line, 'avg-cpu:')) {
                continue;
            }
            $labelPart = trim((string) preg_replace('/^\s*avg-cpu:\s*/i', '', $line));
            $labels = $labelPart !== '' ? preg_split('/\s+/', $labelPart) : [];
            if ($labels === [] || $labels === false) {
                continue;
            }
            $j = $i + 1;
            while ($j < count($lines) && trim((string) $lines[$j]) === '') {
                $j++;
            }
            if ($j >= count($lines)) {
                return null;
            }
            $valueParts = preg_split('/\s+/', trim((string) $lines[$j]));
            if ($valueParts === [] || $valueParts === false) {
                return null;
            }
            $out = [];
            foreach ($labels as $k => $lab) {
                $key = ltrim((string) $lab, '%');
                $out[$key] = isset($valueParts[$k]) ? (float) $valueParts[$k] : 0.0;
            }

            return $out;
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @return list<array<string, mixed>>
     */
    private function extractDevices(array $lines): array
    {
        $headerIdx = null;
        foreach ($lines as $i => $line) {
            if (str_contains((string) $line, 'Device') && str_contains((string) $line, '%util')) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null) {
            throw new \RuntimeException('device header row not found');
        }

        $headers = preg_split('/\s+/', trim((string) $lines[$headerIdx]));
        if ($headers === false || $headers === []) {
            throw new \RuntimeException('empty device header');
        }

        $col = [];
        foreach (['Device' => 'device', 'r/s' => 'r_per_s', 'rkB/s' => 'rkB_per_s', 'w/s' => 'w_per_s', 'wkB/s' => 'wkB_per_s', 'aqu-sz' => 'aqu_sz', '%util' => 'util_percent'] as $needle => $name) {
            $idx = array_search($needle, $headers, true);
            if ($idx !== false) {
                $col[$name] = (int) $idx;
            }
        }

        $need = ['device', 'util_percent'];
        foreach ($need as $k) {
            if (! isset($col[$k])) {
                throw new \RuntimeException('missing column '.$k.' in header');
            }
        }

        $rows = [];

        for ($i = $headerIdx + 1; $i < count($lines); $i++) {
            $block = trim((string) $lines[$i]);
            if ($block === '') {
                break;
            }

            $parts = preg_split('/\s+/', $block);
            if ($parts === false || count($parts) < 10) {
                continue;
            }

            $device = $parts[0];

            if (preg_match('/^Average/i', $device)) {
                continue;
            }

            if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_.\-]*$/', $device)) {
                continue;
            }

            $utilIdx = $col['util_percent'];
            if ($utilIdx >= count($parts)) {
                continue;
            }

            $util = round((float) $parts[$utilIdx], 2);

            $row = ['device' => $device, 'util_percent' => $util];

            foreach (['r_per_s', 'rkB_per_s', 'w_per_s', 'wkB_per_s', 'aqu_sz'] as $field) {
                if (isset($col[$field]) && isset($parts[$col[$field]])) {
                    $row[$field] = round((float) $parts[$col[$field]], 2);
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function includeInPeakUi(string $device): bool
    {
        $d = strtolower($device);

        if (str_starts_with($d, 'loop')) {
            return false;
        }

        if (preg_match('/^sr\d+$/', $d)) {
            return false;
        }

        if ($d === 'fd0' || $d === 'fd1') {
            return false;
        }

        if (str_contains($d, 'ram')) {
            return false;
        }

        return true;
    }

    private function rollupLevel(float $maxUtil, float $ioWait, int $deviceCountPeak): string
    {
        if ($deviceCountPeak === 0) {
            return 'unknown';
        }

        if ($maxUtil >= 90 || $ioWait >= 35) {
            return 'critical';
        }
        if ($maxUtil >= 70 || $ioWait >= 20) {
            return 'warning';
        }

        return 'good';
    }

    private function rollupHeadline(string $level, ?float $maxUtil, ?float $ioWait): string
    {
        return match ($level) {
            'critical' => 'Disks saturated or heavy IO wait',
            'warning' => 'Storage subsystem busy — watch latency',
            'unknown' => 'No block devices judged for rollup',
            default => 'Disk IO looks comfortable',
        };
    }

    private function rollupDetail(
        string $level,
        ?string $maxDevice,
        ?float $maxUtil,
        ?float $meanUtil,
        ?float $ioWait,
        int $totalDevices,
    ): string {
        if ($level === 'unknown') {
            return 'iostat ran but filters excluded all devices—check snapshots below.';
        }

        $chunks = [];

        if ($maxUtil !== null && $maxDevice !== null) {
            $chunks[] = sprintf(
                '%s peaked at %.1f %% util.',
                $maxDevice,
                $maxUtil,
            );
        }

        if ($meanUtil !== null && $meanUtil !== $maxUtil) {
            $chunks[] = sprintf('Mean %.1f %% util across tracked drives.', round($meanUtil, 1));
        }

        if ($ioWait !== null) {
            $chunks[] = sprintf('Kernel reported %.1f %% iowait (CPU stalled on IO).', $ioWait);
        }

        $chunks[] = sprintf('Sampled %d block devices.', $totalDevices);

        return implode(' ', array_filter($chunks));
    }

    /**
     * @param  array{ok?: bool, command?: string|null, processes?: list<array<string, mixed>>, error?: string|null}|null  $processIo
     * @return array<string, mixed>
     */
    private function failure(float $started, string $msg, ?array $processIo = null): array
    {
        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        $processIo ??= ['ok' => false, 'command' => null, 'processes' => [], 'error' => null];

        return [
            'ok' => false,
            'ts' => now()->toIso8601String(),
            'elapsed_ms' => $elapsedMs,
            'interval_sec' => (int) config('monitor.iostat_interval', 1),
            'error' => $msg,
            'cpu' => null,
            'devices' => [],
            'processes' => $processIo['processes'] ?? [],
            'process_io' => [
                'ok' => $processIo['ok'] ?? false,
                'command' => $processIo['command'] ?? null,
                'error' => $processIo['error'] ?? null,
            ],
            'summary' => [
                'level' => 'unknown',
                'headline' => 'Disk metrics unavailable',
                'detail' => $msg,
                'max_util_percent' => null,
                'max_util_device' => null,
                'mean_util_percent' => null,
                'iowait_percent' => null,
                'device_count_reported' => 0,
                'device_count_peak' => 0,
                'top_io_service' => null,
                'top_io_pid' => null,
            ],
        ];
    }
}
