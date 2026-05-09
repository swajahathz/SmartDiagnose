<?php

namespace App\Http\Controllers;

use App\Services\DashboardHealthService;
use App\Services\MySqlLogTailService;
use App\Services\MySqlMonitorService;
use App\Services\SystemMetricsService;
use Illuminate\Http\JsonResponse;

class MonitorMetricsController extends Controller
{
    public function __invoke(
        SystemMetricsService $system,
        MySqlMonitorService $mysql,
        MySqlLogTailService $logTail,
        DashboardHealthService $health,
    ): JsonResponse {
        $sys = $system->snapshot();
        $db = $mysql->collect($logTail);
        $ts = now()->toIso8601String();

        if (! $db['ok']) {
            return response()->json([
                'ok' => false,
                'ts' => $ts,
                'server' => $sys,
                'mysql_error' => $db['error'] ?? 'Unknown error',
                'health' => $health->evaluate($sys, false, null, $db['error'] ?? null),
            ]);
        }

        return response()->json([
            'ok' => true,
            'ts' => $ts,
            'server' => $sys,
            'mysql' => $db['mysql'],
            'health' => $health->evaluate($sys, true, $db['mysql'], null),
        ]);
    }
}
