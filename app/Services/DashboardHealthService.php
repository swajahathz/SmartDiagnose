<?php

namespace App\Services;

class DashboardHealthService
{
    /**
     * Realtime rollup for dashboard UI.
     *
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>|null  $mysql
     * @return array{
     *   level: 'good'|'warning'|'critical',
     *   title: string,
     *   blurb: string,
     *   items: array<int, array{tier: 'good'|'warning'|'critical', label: string, detail: string}>
     * }
     */
    public function evaluate(array $server, bool $mysqlOk, ?array $mysql, ?string $mysqlError): array
    {
        $items = [];

        if (! $mysqlOk) {
            $err = trim((string) ($mysqlError ?? ''));
            if ($err === '') {
                $err = 'Connection refused or credentials rejected.';
            }
            $detail = strlen($err) > 220 ? substr($err, 0, 217).'…' : $err;
            $items[] = [
                'tier' => 'critical',
                'label' => 'MySQL connectivity',
                'detail' => 'The dashboard cannot maintain a monitoring connection: '.$detail,
            ];
        } else {
            $items[] = [
                'tier' => 'good',
                'label' => 'MySQL connectivity',
                'detail' => 'Monitoring credentials reach the server and GLOBAL STATUS queries succeed.',
            ];
            $items = array_merge($items, $this->mysqlSignals($mysql ?? []));
        }

        $items = array_merge($items, $this->hostSignals($server));

        $level = 'good';
        foreach ($items as $it) {
            if (($it['tier'] ?? '') === 'critical') {
                $level = 'critical';
                break;
            }
            if (($it['tier'] ?? '') === 'warning') {
                $level = 'warning';
            }
        }

        usort($items, fn (array $a, array $b) => $this->tierRank($b['tier'] ?? '') <=> $this->tierRank($a['tier'] ?? ''));

        return [
            'level' => $level,
            'title' => match ($level) {
                'critical' => 'Critical — action needed',
                'warning' => 'Watch list — elevated risk',
                default => 'Healthy — all signals green',
            },
            'blurb' => $this->pickBlurb($level, $mysqlOk),
            'items' => array_values($items),
        ];
    }

    private function tierRank(string $tier): int
    {
        return match ($tier) {
            'critical' => 3,
            'warning' => 2,
            default => 1,
        };
    }

    /** @param  array<string, mixed>  $mysql */
    private function mysqlSignals(array $mysql): array
    {
        $items = [];
        $st = $mysql['status'] ?? [];
        $vars = $mysql['variables'] ?? [];

        $threadsRun = isset($st['Threads_running']) ? (int) $st['Threads_running'] : null;
        if ($threadsRun !== null) {
            if ($threadsRun >= 48) {
                $items[] = [
                    'tier' => 'critical',
                    'label' => 'Concurrent running threads',
                    'detail' => "Threads_running is {$threadsRun}. Many queries are executing at once; CPU, locks, or disk may be saturated.",
                ];
            } elseif ($threadsRun >= 22) {
                $items[] = [
                    'tier' => 'warning',
                    'label' => 'Concurrent running threads',
                    'detail' => "Threads_running is {$threadsRun}. Workload is hot—monitor slow queries and temp usage.",
                ];
            } else {
                $items[] = [
                    'tier' => 'good',
                    'label' => 'Concurrent running threads',
                    'detail' => "Threads_running is {$threadsRun}. Query concurrency looks normal.",
                ];
            }
        }

        $maxConn = isset($vars['max_connections']) ? (int) $vars['max_connections'] : null;
        $curConn = isset($st['Threads_connected']) ? (int) $st['Threads_connected'] : null;
        if ($maxConn !== null && $maxConn > 0 && $curConn !== null) {
            $pct = ($curConn / $maxConn) * 100;
            if ($pct >= 97) {
                $items[] = [
                    'tier' => 'critical',
                    'label' => 'Connection headroom',
                    'detail' => sprintf(
                        '%d of %d max_connections (%.0f%%). New connections may be refused imminently.',
                        $curConn,
                        $maxConn,
                        $pct
                    ),
                ];
            } elseif ($pct >= 86) {
                $items[] = [
                    'tier' => 'warning',
                    'label' => 'Connection headroom',
                    'detail' => sprintf(
                        '%d of %d max_connections (%.0f%%). Consider pooling or raising the limit before traffic spikes.',
                        $curConn,
                        $maxConn,
                        $pct
                    ),
                ];
            } else {
                $items[] = [
                    'tier' => 'good',
                    'label' => 'Connection headroom',
                    'detail' => sprintf(
                        '%d of %d max_connections used (%.0f%%)—comfortable slack.',
                        $curConn,
                        $maxConn,
                        $pct
                    ),
                ];
            }
        }

        $reads = isset($st['Innodb_buffer_pool_reads']) ? (int) $st['Innodb_buffer_pool_reads'] : null;
        $req = isset($st['Innodb_buffer_pool_read_requests']) ? (int) $st['Innodb_buffer_pool_read_requests'] : null;
        if ($req !== null && $req > 50_000 && $reads !== null) {
            $missRatio = $reads / $req;
            if ($missRatio >= 0.08) {
                $items[] = [
                    'tier' => 'critical',
                    'label' => 'InnoDB buffer pool',
                    'detail' => sprintf(
                        '%.2f%% of InnoDB logical reads miss the buffer pool—disk IO is dominating; raise innodb_buffer_pool_size or upgrade storage.',
                        $missRatio * 100
                    ),
                ];
            } elseif ($missRatio >= 0.02) {
                $items[] = [
                    'tier' => 'warning',
                    'label' => 'InnoDB buffer pool',
                    'detail' => sprintf(
                        '%.2f%% read miss rate hints working set exceeds RAM or cold caches after restart.',
                        $missRatio * 100
                    ),
                ];
            } else {
                $items[] = [
                    'tier' => 'good',
                    'label' => 'InnoDB buffer pool',
                    'detail' => sprintf('%.2f%% miss rate—data is largely served from memory.', $missRatio * 100),
                ];
            }
        }

        return $items;
    }

    /** @param  array<string, mixed>  $server */
    private function hostSignals(array $server): array
    {
        $items = [];

        $cpu = $server['cpu_percent'] ?? null;
        if (is_numeric($cpu)) {
            $c = (float) $cpu;
            if ($c >= 90) {
                $items[] = [
                    'tier' => 'critical',
                    'label' => 'Host CPU',
                    'detail' => sprintf('Host CPU is %.1f%% busy—expect query latency to climb until load drops.', $c),
                ];
            } elseif ($c >= 74) {
                $items[] = [
                    'tier' => 'warning',
                    'label' => 'Host CPU',
                    'detail' => sprintf('Host CPU is %.1f%%—manageable, but sustained high usage leaves little burst room.', $c),
                ];
            } else {
                $items[] = [
                    'tier' => 'good',
                    'label' => 'Host CPU',
                    'detail' => sprintf('Host CPU is %.1f%% with headroom for spikes.', $c),
                ];
            }
        } else {
            $items[] = [
                'tier' => 'good',
                'label' => 'Host CPU',
                'detail' => 'CPU percentage is unavailable (procfs blocked). Other host checks still apply.',
            ];
        }

        $mem = $server['memory'] ?? null;
        if (is_array($mem) && isset($mem['used_percent'])) {
            $p = (float) $mem['used_percent'];
            if ($p >= 93) {
                $items[] = [
                    'tier' => 'critical',
                    'label' => 'Host RAM',
                    'detail' => sprintf('%.1f%% RAM in use—swap thrash or OOM risk if workload grows.', $p),
                ];
            } elseif ($p >= 81) {
                $items[] = [
                    'tier' => 'warning',
                    'label' => 'Host RAM',
                    'detail' => sprintf('%.1f%% RAM in use; keep an eye on buffers and large result sets.', $p),
                ];
            } else {
                $items[] = [
                    'tier' => 'good',
                    'label' => 'Host RAM',
                    'detail' => sprintf('%.1f%% RAM in use—free memory can still cache hot InnoDB pages.', $p),
                ];
            }
        } else {
            $items[] = [
                'tier' => 'good',
                'label' => 'Host RAM',
                'detail' => 'RAM usage not available from /proc; cannot score memory pressure here.',
            ];
        }

        $load = $server['load'] ?? null;
        $l1 = is_array($load) && isset($load['1m']) ? (float) $load['1m'] : null;
        $cpus = isset($server['logical_cpus']) ? (int) $server['logical_cpus'] : null;
        if ($l1 !== null) {
            if ($cpus !== null && $cpus > 0) {
                $ratio = $l1 / $cpus;
                if ($ratio >= 1.8) {
                    $items[] = [
                        'tier' => 'critical',
                        'label' => 'Load average (1m)',
                        'detail' => sprintf(
                            'Load %.2f with %d logical CPUs (%.2f× per core) — run queue is overloaded.',
                            $l1,
                            $cpus,
                            $ratio
                        ),
                    ];
                } elseif ($ratio >= 1.1) {
                    $items[] = [
                        'tier' => 'warning',
                        'label' => 'Load average (1m)',
                        'detail' => sprintf(
                            'Load %.2f with %d CPUs (%.2f×) — CPU or IO wait is building ahead of capacity.',
                            $l1,
                            $cpus,
                            $ratio
                        ),
                    ];
                } else {
                    $items[] = [
                        'tier' => 'good',
                        'label' => 'Load average (1m)',
                        'detail' => sprintf('Load %.2f with %d CPUs (%.2f×) tracks a calm host.', $l1, $cpus, $ratio),
                    ];
                }
            } elseif ($l1 >= 14) {
                $items[] = [
                    'tier' => 'critical',
                    'label' => 'Load average (1m)',
                    'detail' => sprintf('Load %.2f is extreme without a verified CPU count—investigate top consumers.', $l1),
                ];
            } elseif ($l1 >= 6) {
                $items[] = [
                    'tier' => 'warning',
                    'label' => 'Load average (1m)',
                    'detail' => sprintf(
                        'Load %.2f is elevated. CPU/core count unavailable, so verify with server tooling.',
                        $l1
                    ),
                ];
            } else {
                $items[] = [
                    'tier' => 'good',
                    'label' => 'Load average (1m)',
                    'detail' => sprintf('Load %.2f looks tame for typical VPS sizing.', $l1),
                ];
            }
        } else {
            $items[] = [
                'tier' => 'good',
                'label' => 'Load average',
                'detail' => 'Cannot read load averages from the host.',
            ];
        }

        return $items;
    }

    private function pickBlurb(string $level, bool $mysqlOk): string
    {
        if (! $mysqlOk && $level === 'critical') {
            return 'Restore MySQL access first; meanwhile host metrics explain whether the OS layer is strained.';
        }

        return match ($level) {
            'critical' => 'Real-time thresholds show one or more stress signals. Use the bullets to decide what to fix first.',
            'warning' => 'Nothing is alarming yet, but several indicators are edging toward saturation—schedule a tune-up.',
            default => 'MySQL responsiveness, concurrency, disk cache hits, CPU, RAM, and load averages all look reassuring right now.',
        };
    }
}
