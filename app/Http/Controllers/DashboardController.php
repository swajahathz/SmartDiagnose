<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('dashboard', [
            'pollSeconds' => 3,
            'diskPollSeconds' => (float) config('monitor.disk_poll_seconds', 5),
            'authEnabled' => filled(config('monitor.auth.user')) && config('monitor.auth.password') !== '',
        ]);
    }
}
