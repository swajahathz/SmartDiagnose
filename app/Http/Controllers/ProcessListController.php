<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class ProcessListController extends Controller
{
    public function __invoke(): View
    {
        $pollSec = (float) config('monitor.realtime_poll_seconds', 1.5);
        $pollMs = max(300, (int) round($pollSec * 1000));

        return view('queries', [
            'pollMs' => $pollMs,
            'pollLabel' => sprintf('Refresh: %ss', $pollSec == (int) $pollSec ? (string) (int) $pollSec : sprintf('%.1f', $pollSec)),
            'authEnabled' => filled(config('monitor.auth.user')) && config('monitor.auth.password') !== '',
        ]);
    }
}
