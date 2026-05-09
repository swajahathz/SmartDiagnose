<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class FreeradiusLogController extends Controller
{
    public function __invoke(): View
    {
        return view('freeradius-log', [
            'pollSeconds' => max(2.0, (float) config('monitor.freeradius_poll_seconds', 3)),
        ]);
    }
}
