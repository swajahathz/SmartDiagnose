<?php

namespace App\Http\Controllers;

use App\Services\FreeradiusLogTailService;
use Illuminate\Http\JsonResponse;

class FreeradiusLogJsonController extends Controller
{
    public function __invoke(FreeradiusLogTailService $tail): JsonResponse
    {
        $data = $tail->tail();

        return response()->json(array_merge($data, [
            'ts' => now()->toIso8601String(),
        ]));
    }
}
