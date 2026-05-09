<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class SlowQueriesController extends Controller
{
    public function __invoke(): View
    {
        $sec = (float) config('monitor.slow_queries_poll_seconds', 3);
        $pollMs = max(500, (int) round($sec * 1000));

        return view('slow-queries', [
            'pollMs' => $pollMs,
            'pollLabel' => sprintf('Refresh: %ss', $sec == (int) $sec ? (string) (int) $sec : sprintf('%.1f', $sec)),
        ]);
    }
}
