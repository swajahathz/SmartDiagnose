<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DriveStatusController;
use App\Http\Controllers\DriveStatusMetricsController;
use App\Http\Controllers\MonitorMetricsController;
use App\Http\Controllers\ProcessListController;
use App\Http\Controllers\ProcessListJsonController;
use App\Http\Controllers\SlowQueriesController;
use App\Http\Controllers\SlowQueriesJsonController;
use Illuminate\Support\Facades\Route;

Route::middleware(['monitor.auth'])->group(function () {
    Route::middleware('throttle:120,1')->group(function () {
        Route::get('/', DashboardController::class);
        Route::get('/queries', ProcessListController::class)->name('queries');
        Route::get('/slow-queries', SlowQueriesController::class)->name('slow-queries');
        Route::get('/drives', DriveStatusController::class)->name('drives');
        Route::get('/api/metrics', MonitorMetricsController::class)->name('api.metrics');
        Route::get('/api/processlist', ProcessListJsonController::class)->name('api.processlist');
        Route::get('/api/slow-queries', SlowQueriesJsonController::class)->name('api.slow-queries');
    });

    Route::middleware('throttle:40,1')->group(function () {
        Route::get('/api/drive-status', DriveStatusMetricsController::class)->name('api.drive-status');
    });
});
