<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MySQL error log tail
    |--------------------------------------------------------------------------
    */

    'log_tail_lines' => (int) env('MONITOR_LOG_TAIL_LINES', 80),

    'log_tail_enabled' => filter_var(env('MONITOR_LOG_TAIL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Process list & slow query UI limits
    |--------------------------------------------------------------------------
    */

    'processlist_limit' => (int) env('MONITOR_PROCESSLIST_LIMIT', 40),

    /*
    | Max rows on the dedicated "Live queries" page (SHOW FULL PROCESSLIST).
    */
    'processlist_realtime_limit' => (int) env('MONITOR_PROCESSLIST_REALTIME_LIMIT', 100),

    /*
    | How often the Live queries page refreshes (seconds). Can be fractional e.g. 1.5
    */
    'realtime_poll_seconds' => (float) env('MONITOR_REALTIME_POLL_SECONDS', 1.5),

    'slow_digest_limit' => (int) env('MONITOR_SLOW_DIGEST_LIMIT', 12),

    /*
    | Dedicated "Slow queries" page (poll interval, digest & log limits)
    */
    'slow_queries_poll_seconds' => (float) env('MONITOR_SLOW_QUERIES_POLL_SECONDS', 3),

    'slow_queries_digest_limit' => (int) env('MONITOR_SLOW_QUERIES_DIGEST_LIMIT', 25),

    'slow_queries_log_tail_lines' => (int) env('MONITOR_SLOW_QUERIES_LOG_TAIL_LINES', 80),

    'slow_queries_table_limit' => (int) env('MONITOR_SLOW_QUERIES_TABLE_LIMIT', 30),

    /*
    |--------------------------------------------------------------------------
    | Long-running query threshold (seconds) for highlighting
    |--------------------------------------------------------------------------
    */

    'long_query_seconds' => (float) env('MONITOR_LONG_QUERY_SECONDS', 3),

    /*
    |--------------------------------------------------------------------------
    | Block device IO (sysstat `iostat`)
    |--------------------------------------------------------------------------
    |
    | The drive dashboard executes: iostat -y -x {interval} 1
    | `-y`: skip since-boot totals; `{interval}`: sample seconds (Ubuntu iostat semantics).
    |
    */

    'disk_poll_seconds' => max(3, (float) env('MONITOR_DISK_POLL_SECONDS', 5)),

    'iostat_interval' => max(1, min(60, (int) env('MONITOR_IOSTAT_INTERVAL', 1))),

    'iostat_binary' => env('MONITOR_IOSTAT_BINARY'),

    /*
    |--------------------------------------------------------------------------
    | FreeRADIUS log tail (file on disk)
    |--------------------------------------------------------------------------
    |
    | freeradius -X is foreground debug; logs must be written to a file this user
    | can read (redirect, or log { } in FreeRADIUS config).
    |
    */

    'freeradius_log_enabled' => filter_var(env('FREERADIUS_LOG_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'freeradius_log_path' => env('FREERADIUS_LOG_PATH'),

    'freeradius_log_lines' => max(20, min(5000, (int) env('FREERADIUS_LOG_LINES', 300))),

    'freeradius_poll_seconds' => max(2, (float) env('FREERADIUS_POLL_SECONDS', 3)),

];
