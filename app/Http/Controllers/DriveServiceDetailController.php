<?php

namespace App\Http\Controllers;

use App\Services\FreeradiusLogTailService;
use App\Services\MySqlMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriveServiceDetailController extends Controller
{
    public function __invoke(
        Request $request,
        MySqlMonitorService $mysql,
        FreeradiusLogTailService $freeradius,
    ): JsonResponse {
        $service = trim((string) $request->query('service', ''));
        $command = trim((string) $request->query('command', ''));
        $pid = (int) $request->query('pid', 0);
        $kind = $this->classify($service, $command);

        $base = [
            'ts' => now()->toIso8601String(),
            'service' => $service !== '' ? $service : $command,
            'command' => $command,
            'pid' => $pid > 0 ? $pid : null,
            'kind' => $kind,
        ];

        if ($kind === 'mysql') {
            $detail = $mysql->fetchMysqlIoDetail();

            return response()->json(array_merge($base, $detail));
        }

        if ($kind === 'freeradius') {
            $tail = $freeradius->tail();
            $lines = array_slice($tail['lines'] ?? [], -40);

            return response()->json(array_merge($base, [
                'ok' => true,
                'summary' => [
                    'hint' => 'FreeRADIUS disk IO is usually SQL accounting (INSERT/UPDATE into radius DB via MySQL). Open MySQL row for active queries, or inspect recent log below.',
                    'log_path' => $tail['path'] ?? null,
                    'log_error' => $tail['error'] ?? null,
                ],
                'log_lines' => $lines,
                'related' => [
                    'open_mysql' => true,
                    'label' => 'Also check MySQL processlist (radius accounting)',
                ],
            ]));
        }

        if ($kind === 'journal' || $kind === 'rsyslog') {
            return response()->json(array_merge($base, [
                'ok' => true,
                'summary' => [
                    'hint' => $kind === 'journal'
                        ? 'systemd-journald is flushing logs to disk. Reduce noisy units or rate-limit journal if writes stay high.'
                        : 'rsyslog is writing log files. Check /var/log growth and rsyslog rules.',
                ],
                'queries' => [],
                'log_lines' => [],
            ]));
        }

        if ($kind === 'ext4_journal') {
            return response()->json(array_merge($base, [
                'ok' => true,
                'summary' => [
                    'hint' => 'ext4 journal (jbd2) reflects filesystem commit traffic—usually driven by writers above (often MySQL). Click the top writer service for SQL details.',
                ],
                'queries' => [],
            ]));
        }

        return response()->json(array_merge($base, [
            'ok' => true,
            'summary' => [
                'hint' => 'No SQL processlist for this process. For query text, click the MySQL row (or open Live queries).',
            ],
            'queries' => [],
        ]));
    }

    private function classify(string $service, string $command): string
    {
        $s = strtolower($service.' '.$command);

        if (str_contains($s, 'mysqld') || str_contains($s, 'mariadb') || $service === 'MySQL' || $service === 'MariaDB') {
            return 'mysql';
        }
        if (str_contains($s, 'freeradius') || str_contains($s, 'radiusd') || $service === 'FreeRADIUS') {
            return 'freeradius';
        }
        if (str_contains($s, 'systemd-journal') || $service === 'journald') {
            return 'journal';
        }
        if (str_contains($s, 'rsyslog') || $service === 'rsyslog') {
            return 'rsyslog';
        }
        if (str_contains($s, 'jbd2') || $service === 'ext4 journal') {
            return 'ext4_journal';
        }

        return 'generic';
    }
}
