<?php

namespace App\Http\Controllers;

use App\Services\MySqlMonitorService;
use Illuminate\Http\JsonResponse;

class ProcessListJsonController extends Controller
{
    public function __invoke(MySqlMonitorService $mysql): JsonResponse
    {
        $data = $mysql->fetchProcessListForApi();

        return response()->json([
            'ok' => $data['ok'],
            'ts' => now()->toIso8601String(),
            'rows' => $data['rows'],
            'error' => $data['error'] ?? null,
        ]);
    }
}
