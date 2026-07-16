<?php

namespace App\Services;

class SystemMetricsService
{
    /** @var list<string> */
    private const PROC_WHITELIST = ['/proc/loadavg', '/proc/stat', '/proc/meminfo', '/proc/cpuinfo'];

    public function snapshot(): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return [
                'os' => PHP_OS_FAMILY,
                'load' => null,
                'cpu_percent' => null,
                'memory' => null,
                'logical_cpus' => null,
            ];
        }

        return [
            'os' => 'Linux',
            'load' => $this->loadAverage(),
            'cpu_percent' => $this->cpuUsagePercent(),
            'memory' => $this->memoryInfo(),
            'logical_cpus' => $this->logicalCpuCount(),
        ];
    }

    /**
     * Read whitelisted procfs files. Uses cat via shell when open_basedir blocks file_get_contents().
     */
    private function procContents(string $path): ?string
    {
        if (! in_array($path, self::PROC_WHITELIST, true)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw !== false && $raw !== '') {
            return $raw;
        }

        if (! function_exists('shell_exec')) {
            return null;
        }

        $out = shell_exec('cat '.escapeshellarg($path).' 2>/dev/null');
        if (! is_string($out) || $out === '') {
            return null;
        }

        return $out;
    }

    private function logicalCpuCount(): ?int
    {
        $blob = $this->procContents('/proc/cpuinfo');
        if ($blob === null || $blob === '') {
            return null;
        }

        if (preg_match_all('/^processor\s*:/mi', $blob, $m) !== false) {
            $n = count($m[0]);

            return $n > 0 ? $n : null;
        }

        return null;
    }

    /**
     * @return array{1m: float, 5m: float, 15m: float}|null
     */
    private function loadAverage(): ?array
    {
        $raw = $this->procContents('/proc/loadavg');
        if ($raw !== null && $raw !== '') {
            $parts = preg_split('/\s+/', trim($raw));
            if (is_array($parts) && count($parts) >= 3) {
                return [
                    '1m' => (float) $parts[0],
                    '5m' => (float) $parts[1],
                    '15m' => (float) $parts[2],
                ];
            }
        }

        $avg = @sys_getloadavg();

        return is_array($avg) && count($avg) >= 3
            ? ['1m' => (float) $avg[0], '5m' => (float) $avg[1], '15m' => (float) $avg[2]]
            : null;
    }

    private function cpuUsagePercent(): ?float
    {
        $a = $this->readCpuAggregate();
        if ($a === null) {
            return null;
        }

        usleep(150_000);

        $b = $this->readCpuAggregate();
        if ($b === null) {
            return null;
        }

        $idle = $b['idle'] - $a['idle'];
        $total = $b['total'] - $a['total'];

        if ($total <= 0) {
            return null;
        }

        $usage = (1 - ($idle / $total)) * 100;

        return round(max(0, min(100, $usage)), 1);
    }

    /**
     * @return array{idle: float, total: float}|null
     */
    private function readCpuAggregate(): ?array
    {
        $blob = $this->procContents('/proc/stat');
        if ($blob === null) {
            return null;
        }

        $aggregate = null;
        foreach (preg_split("/\r\n|\n|\r/", $blob, -1, PREG_SPLIT_NO_EMPTY) as $row) {
            if (str_starts_with($row, 'cpu ')) {
                $aggregate = $row;
                break;
            }
        }

        if ($aggregate === null) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($aggregate));
        array_shift($parts);

        $nums = array_map(static fn ($v) => (float) $v, $parts);
        $idle = ($nums[3] ?? 0) + ($nums[4] ?? 0);
        $total = array_sum($nums);

        return ['idle' => $idle, 'total' => $total];
    }

    /**
     * @return array{total_kb: int, available_kb: int, used_percent: float}|null
     */
    private function memoryInfo(): ?array
    {
        $text = $this->procContents('/proc/meminfo');
        if ($text === null) {
            return null;
        }

        $lines = preg_split("/\r\n|\n|\r/", $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($lines === []) {
            return null;
        }

        $map = [];
        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s+kB/i', $line, $m)) {
                $map[strtolower($m[1])] = (int) $m[2];
            }
        }

        $total = $map['memtotal'] ?? null;
        if ($total === null || $total <= 0) {
            return null;
        }

        $avail = $map['memavailable'] ?? $map['memfree'] ?? null;
        if ($avail === null) {
            return null;
        }

        $used = $total - $avail;
        $pct = round(($used / $total) * 100, 1);

        return [
            'total_kb' => $total,
            'available_kb' => $avail,
            'used_percent' => min(100, max(0, $pct)),
        ];
    }
}
