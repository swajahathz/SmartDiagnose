<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class DriveStatusController extends Controller
{
    public function __invoke(): View
    {
        return view('drive-status', [
            'pollSeconds' => (float) config('monitor.disk_poll_seconds', 5),
        ]);
    }
}
