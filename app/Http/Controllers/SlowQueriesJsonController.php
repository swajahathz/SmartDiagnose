<?php

namespace App\Http\Controllers;

use App\Services\MySqlMonitorService;
use Illuminate\Http\JsonResponse;

class SlowQueriesJsonController extends Controller
{
    public function __invoke(MySqlMonitorService $mysql): JsonResponse
    {
        $payload = $mysql->fetchSlowQueriesForApi();

        return response()->json(array_merge(
            ['ts' => now()->toIso8601String()],
            $payload
        ));
    }
}
