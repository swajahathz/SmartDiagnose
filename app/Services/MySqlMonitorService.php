<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MySqlMonitorService
{
    private const STATUS_KEYS = [
        'Uptime',
        'Queries',
        'Questions',
        'Slow_queries',
        'Threads_connected',
        'Threads_running',
        'Max_used_connections',
        'Aborted_connects',
        'Bytes_received',
        'Bytes_sent',
        'Com_select',
        'Com_insert',
        'Com_update',
        'Com_delete',
        'Table_locks_immediate',
        'Table_locks_waited',
        'Created_tmp_disk_tables',
        'Created_tmp_tables',
        'Select_full_join',
        'Select_scan',
        'Sort_merge_passes',
        'Innodb_buffer_pool_reads',
        'Innodb_buffer_pool_read_requests',
        'Innodb_rows_read',
        'Innodb_rows_inserted',
        'Innodb_rows_updated',
        'Innodb_rows_deleted',
        'Open_tables',
        'Opened_tables',
    ];

    private const VARIABLE_KEYS = [
        'version',
        'version_comment',
        'max_connections',
        'slow_query_log',
        'long_query_time',
        'log_error',
        'max_allowed_packet',
        'innodb_buffer_pool_size',
        'innodb_log_file_size',
    ];

    /**
     * @return array{ok: bool, mysql?: array<string, mixed>, error?: string}
     */
    public function collect(MySqlLogTailService $logTail): array
    {
        try {
            DB::connection('monitor')->getPdo();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $status = $this->globalStatusMap();
            $variables = $this->globalVariablesMap();
            $qps = $this->computeQps((float) ($status['Queries'] ?? $status['Questions'] ?? 0));
            $processlist = $this->processlist();
            $slowDigests = $this->slowStatementDigests();
            $replica = $this->replicaStatus();
            $logTailData = $logTail->tailErrorLog(config('monitor.log_tail_lines', 80));

            return [
                'ok' => true,
                'mysql' => [
                    'status' => $status,
                    'variables' => $variables,
                    'queries_per_sec' => $qps,
                    'uptime_seconds' => (int) ($status['Uptime'] ?? 0),
                    'processlist' => $processlist,
                    'slow_digests' => $slowDigests,
                    'replica' => $replica,
                    'error_log' => $logTailData,
                ],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, string>
     */
    private function globalStatusMap(): array
    {
        $rows = DB::connection('monitor')->select('SHOW GLOBAL STATUS');
        $out = [];
        foreach ($rows as $row) {
            $name = $row->Variable_name ?? $row->variable_name ?? null;
            $value = $row->Value ?? $row->value ?? null;
            if (is_string($name)) {
                $out[$name] = (string) $value;
            }
        }

        $filtered = [];
        foreach (self::STATUS_KEYS as $key) {
            if (array_key_exists($key, $out)) {
                $filtered[$key] = $out[$key];
            }
        }

        return $filtered;
    }

    /**
     * @return array<string, string>
     */
    private function globalVariablesMap(): array
    {
        $rows = DB::connection('monitor')->select('SHOW GLOBAL VARIABLES');
        $map = [];
        foreach ($rows as $row) {
            $name = strtolower((string) ($row->Variable_name ?? $row->variable_name ?? ''));
            $value = $row->Value ?? $row->value ?? '';
            if ($name !== '') {
                $map[$name] = (string) $value;
            }
        }

        $out = [];
        foreach (self::VARIABLE_KEYS as $key) {
            if (array_key_exists($key, $map)) {
                $out[$key] = $map[$key];
            }
        }

        return $out;
    }

    private function computeQps(float $queriesTotal): float
    {
        $now = microtime(true);
        $prev = Cache::get('monitor_qps_prev');

        Cache::put('monitor_qps_prev', ['q' => $queriesTotal, 't' => $now], 120);

        if (! is_array($prev) || ! isset($prev['q'], $prev['t'])) {
            return 0.0;
        }

        $dq = $queriesTotal - (float) $prev['q'];
        $dt = $now - (float) $prev['t'];

        if ($dt <= 0 || $dq < 0) {
            return 0.0;
        }

        return round($dq / $dt, 2);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function processlist(): array
    {
        return $this->buildProcessList((int) config('monitor.processlist_limit', 40));
    }

    /**
     * Drive-status click detail: live queries / digests for MySQL IO culprits.
     *
     * @return array{ok: bool, kind: string, error?: string, summary?: array<string, mixed>, queries?: list<array<string, mixed>>, current_statements?: list<array<string, mixed>>, digests?: list<array<string, mixed>>}
     */
    public function fetchMysqlIoDetail(): array
    {
        try {
            DB::connection('monitor')->getPdo();
        } catch (\Throwable $e) {
            return ['ok' => false, 'kind' => 'mysql', 'error' => $e->getMessage()];
        }

        try {
            $longSec = (float) config('monitor.long_query_seconds', 3);
            $raw = DB::connection('monitor')->select('SHOW FULL PROCESSLIST');

            $all = [];
            $sleep = 0;
            $other = 0;
            foreach ($raw as $row) {
                $r = array_change_key_case((array) $row, CASE_LOWER);
                $time = isset($r['time']) ? (float) $r['time'] : 0.0;
                $cmd = strtoupper((string) ($r['command'] ?? ''));
                $info = isset($r['info']) ? $this->truncate((string) $r['info'], 4000) : null;

                if ($cmd === 'SLEEP') {
                    $sleep++;

                    continue;
                }

                $isQuery = in_array($cmd, ['QUERY', 'EXECUTE'], true) && is_string($info) && $info !== '';
                if (! $isQuery) {
                    $other++;
                }

                $all[] = [
                    'Id' => $r['id'] ?? null,
                    'User' => $r['user'] ?? null,
                    'Host' => $r['host'] ?? null,
                    'db' => $r['db'] ?? null,
                    'Command' => $r['command'] ?? null,
                    'Time' => $time,
                    'State' => $r['state'] ?? null,
                    'Info' => $info,
                    'long_running' => $time >= $longSec,
                    'is_query' => $isQuery,
                ];
            }

            $queries = array_values(array_filter($all, static fn (array $r) => ($r['is_query'] ?? false) === true));
            usort($queries, static fn ($a, $b) => ($b['Time'] <=> $a['Time']));
            $queries = array_slice($queries, 0, 40);

            // Non-query busy threads (e.g. Binlog Dump) still useful context.
            $busy = array_values(array_filter($all, static fn (array $r) => ($r['is_query'] ?? false) !== true));
            usort($busy, static fn ($a, $b) => ($b['Time'] <=> $a['Time']));
            $busy = array_slice($busy, 0, 15);

            return [
                'ok' => true,
                'kind' => 'mysql',
                'summary' => [
                    'threads_total' => count($raw),
                    'queries_active' => count($queries),
                    'sleeping' => $sleep,
                    'other_busy' => count($busy),
                    'hint' => count($queries) === 0
                        ? 'No active SQL text right now—IO may be InnoDB flush / commit / background writers. Check top digests below (cumulative).'
                        : 'Active queries below are the current SQL using this MySQL process.',
                ],
                'queries' => $queries,
                'other_threads' => $busy,
                'current_statements' => $this->currentStatements(25),
                'digests' => $this->slowStatementDigests(15),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'kind' => 'mysql', 'error' => $e->getMessage()];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function currentStatements(int $limit): array
    {
        $limit = max(5, min(50, $limit));

        try {
            $sql = <<<SQL
SELECT
  THREAD_ID AS thread_id,
  CURRENT_SCHEMA AS current_schema,
  SQL_TEXT AS sql_text,
  TIMER_WAIT AS timer_wait,
  ROWS_EXAMINED AS rows_examined,
  ROWS_AFFECTED AS rows_affected,
  LOCK_TIME AS lock_time
FROM performance_schema.events_statements_current
WHERE SQL_TEXT IS NOT NULL AND SQL_TEXT != ''
ORDER BY TIMER_WAIT DESC
LIMIT {$limit}
SQL;
            $rows = DB::connection('monitor')->select($sql);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $a = (array) $row;
            $waitPs = isset($a['timer_wait']) ? (float) $a['timer_wait'] : 0.0;
            $lockPs = isset($a['lock_time']) ? (float) $a['lock_time'] : 0.0;
            $out[] = [
                'thread_id' => $a['thread_id'] ?? null,
                'schema' => $a['current_schema'] ?? null,
                'sql_text' => isset($a['sql_text']) ? $this->truncate((string) $a['sql_text'], 4000) : '',
                'seconds' => round($waitPs / 1e12, 4),
                'lock_seconds' => round($lockPs / 1e12, 4),
                'rows_examined' => (int) ($a['rows_examined'] ?? 0),
                'rows_affected' => (int) ($a['rows_affected'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return array{ok: bool, rows: list<array<string, mixed>>, error?: string}
     */
    public function fetchProcessListForApi(): array
    {
        try {
            DB::connection('monitor')->getPdo();
        } catch (\Throwable $e) {
            return ['ok' => false, 'rows' => [], 'error' => $e->getMessage()];
        }

        try {
            $limit = (int) config('monitor.processlist_realtime_limit', 100);

            return [
                'ok' => true,
                'rows' => $this->buildProcessList($limit),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'rows' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildProcessList(int $limit): array
    {
        $rows = DB::connection('monitor')->select('SHOW FULL PROCESSLIST');
        $longSec = (float) config('monitor.long_query_seconds', 3);

        $out = [];
        foreach ($rows as $row) {
            $r = array_change_key_case((array) $row, CASE_LOWER);
            $time = isset($r['time']) ? (float) $r['time'] : 0.0;
            $out[] = [
                'Id' => $r['id'] ?? null,
                'User' => $r['user'] ?? null,
                'Host' => $r['host'] ?? null,
                'db' => $r['db'] ?? null,
                'Command' => $r['command'] ?? null,
                'Time' => $time,
                'State' => $r['state'] ?? null,
                'Info' => isset($r['info']) ? $this->truncate((string) $r['info'], 2000) : null,
                'long_running' => $time >= $longSec,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array{ok: bool, error?: string, long_query_time?: float, running_slow?: list, digests?: list, slow_log_tail?: array, slow_log_table?: list}
     */
    public function fetchSlowQueriesForApi(): array
    {
        try {
            DB::connection('monitor')->getPdo();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $lqt = (float) ($this->mysqlVariableValue('long_query_time') ?? 1);
            $running = $this->runningSlowQueries($lqt);
            $digestLimit = (int) config('monitor.slow_queries_digest_limit', 25);
            $logLines = (int) config('monitor.slow_queries_log_tail_lines', 80);
            $tblLimit = (int) config('monitor.slow_queries_table_limit', 30);

            return [
                'ok' => true,
                'long_query_time' => $lqt,
                'running_slow' => $running,
                'digests' => $this->slowStatementDigests($digestLimit),
                'slow_log_tail' => $this->tailSlowQueryLogFile($logLines),
                'slow_log_table' => $this->mysqlSlowLogTableRows($tblLimit),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runningSlowQueries(float $longQueryTimeSeconds): array
    {
        $all = $this->buildProcessList(800);
        $slow = array_values(array_filter($all, function (array $r) use ($longQueryTimeSeconds) {
            $cmd = strtoupper((string) ($r['Command'] ?? ''));
            if (! in_array($cmd, ['QUERY', 'EXECUTE'], true)) {
                return false;
            }
            $t = (float) ($r['Time'] ?? 0);

            return $t >= $longQueryTimeSeconds;
        }));

        usort($slow, static fn ($a, $b) => ($b['Time'] <=> $a['Time']));

        return $slow;
    }

    private function mysqlVariableValue(string $name): ?string
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            return null;
        }

        try {
            $row = DB::connection('monitor')->selectOne('SHOW GLOBAL VARIABLES LIKE \''.$name.'\'');
            if ($row === null) {
                return null;
            }
            $arr = (array) $row;

            return isset($arr['Value']) ? (string) $arr['Value'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Tail slow query log file (when log_output includes FILE and slow_query_log is ON).
     */
    private function tailSlowQueryLogFile(int $lines): array
    {
        $off = [
            'enabled' => false,
            'path' => null,
            'lines' => [],
            'error' => null,
        ];

        try {
            $onRow = DB::connection('monitor')->selectOne("SHOW VARIABLES LIKE 'slow_query_log'");
            $on = strtoupper((string) (is_object($onRow) ? ($onRow->Value ?? '') : ''));
            if ($on !== 'ON' && $on !== '1') {
                return array_merge($off, ['error' => 'slow_query_log is OFF']);
            }

            $pathRow = DB::connection('monitor')->selectOne("SHOW VARIABLES LIKE 'slow_query_log_file'");
            $path = is_object($pathRow) ? ($pathRow->Value ?? null) : null;
            if (! is_string($path) || $path === '') {
                return array_merge($off, ['error' => 'slow_query_log_file not set']);
            }

            $slice = $this->tailFileLastLines($path, $lines);
            if ($slice === null) {
                return [
                    'enabled' => true,
                    'path' => $path,
                    'lines' => [],
                    'error' => 'File not readable by PHP (check path / permissions)',
                ];
            }

            return [
                'enabled' => true,
                'path' => $path,
                'lines' => $slice,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return array_merge($off, ['error' => $e->getMessage()]);
        }
    }

    /**
     * When log_output=TABLE, recent rows from mysql.slow_log.
     *
     * @return list<array<string, mixed>>
     */
    private function mysqlSlowLogTableRows(int $limit): array
    {
        try {
            $exists = DB::connection('monitor')->selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
                ['mysql', 'slow_log']
            );
            if ((int) ($exists->c ?? 0) === 0) {
                return [];
            }

            $sql = 'SELECT * FROM mysql.slow_log ORDER BY start_time DESC LIMIT '.(int) $limit;
            $rows = DB::connection('monitor')->select($sql);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $a = (array) $row;
            if (isset($a['sql_text'])) {
                $a['sql_text'] = $this->truncate((string) $a['sql_text'], 4000);
            }

            $out[] = $a;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function slowStatementDigests(?int $limitOverride = null): array
    {
        $limit = $limitOverride ?? (int) config('monitor.slow_digest_limit', 12);

        try {
            $dig = DB::connection('monitor')->selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
                ['performance_schema', 'events_statements_summary_by_digest']
            );
            $count = (int) ($dig->c ?? 0);
            if ($count === 0) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        $sql = <<<SQL
SELECT
  DIGEST_TEXT AS digest_text,
  SCHEMA_NAME AS schema_name,
  COUNT_STAR AS count_star,
  SUM_TIMER_WAIT AS sum_timer_wait,
  SUM_ROWS_EXAMINED AS sum_rows_examined,
  FIRST_SEEN AS first_seen,
  LAST_SEEN AS last_seen
FROM performance_schema.events_statements_summary_by_digest
WHERE DIGEST_TEXT IS NOT NULL AND DIGEST_TEXT != ''
ORDER BY SUM_TIMER_WAIT DESC
LIMIT {$limit}
SQL;

        try {
            $rows = DB::connection('monitor')->select($sql);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $a = (array) $row;
            // performance_schema timers are picoseconds
            $waitPs = isset($a['sum_timer_wait']) ? (float) $a['sum_timer_wait'] : 0.0;
            $out[] = [
                'digest_text' => isset($a['digest_text']) ? $this->truncate((string) $a['digest_text'], 400) : '',
                'schema_name' => $a['schema_name'] ?? null,
                'count_star' => (int) ($a['count_star'] ?? 0),
                'total_seconds' => round($waitPs / 1e12, 4),
                'sum_rows_examined' => (int) ($a['sum_rows_examined'] ?? 0),
                'first_seen' => isset($a['first_seen']) ? (string) $a['first_seen'] : null,
                'last_seen' => isset($a['last_seen']) ? (string) $a['last_seen'] : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function replicaStatus(): ?array
    {
        foreach (['SHOW REPLICA STATUS', 'SHOW SLAVE STATUS'] as $sql) {
            try {
                $row = DB::connection('monitor')->select($sql);
            } catch (\Throwable) {
                continue;
            }

            if ($row === []) {
                continue;
            }

            $first = (array) $row[0];
            $pick = [
                'Replica_IO_Running' => $first['Replica_IO_Running'] ?? $first['Slave_IO_Running'] ?? null,
                'Replica_SQL_Running' => $first['Replica_SQL_Running'] ?? $first['Slave_SQL_Running'] ?? null,
                'Seconds_Behind_Source' => $first['Seconds_Behind_Source'] ?? $first['Seconds_Behind_Master'] ?? null,
                'Last_IO_Error' => $first['Last_IO_Error'] ?? null,
                'Last_SQL_Error' => $first['Last_SQL_Error'] ?? null,
            ];

            return array_filter($pick, static fn ($v) => $v !== null && $v !== '');
        }

        return null;
    }

    /**
     * Read only the last N lines without loading the whole file (avoids OOM on large logs).
     *
     * @return list<string>|null null if unreadable
     */
    private function tailFileLastLines(string $path, int $maxLines): ?array
    {
        $maxLines = max(1, min(5000, $maxLines));
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return null;
        }

        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);
        if ($pos === false || $pos <= 0) {
            fclose($fp);

            return [];
        }

        $data = '';
        $chunk = 8192;
        $newlines = 0;
        while ($pos > 0 && $newlines < $maxLines + 1) {
            $read = min($chunk, $pos);
            $pos -= $read;
            fseek($fp, $pos);
            $chunkData = fread($fp, $read);
            if ($chunkData === false) {
                break;
            }
            $data = $chunkData.$data;
            $newlines = substr_count(str_replace("\r\n", "\n", $data), "\n");
        }
        fclose($fp);

        $normalized = str_replace("\r\n", "\n", $data);
        $parts = explode("\n", $normalized);
        if (count($parts) > $maxLines) {
            $parts = array_slice($parts, -$maxLines);
        }

        return array_values($parts);
    }

    private function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max - 3).'...';
    }
}
