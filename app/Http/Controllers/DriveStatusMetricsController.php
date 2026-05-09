<?php

namespace App\Http\Controllers;

use App\Services\IostatService;
use Illuminate\Http\JsonResponse;

class DriveStatusMetricsController extends Controller
{
    public function __invoke(IostatService $iostat): JsonResponse
    {
        return response()->json($iostat->sample());
    }
}
