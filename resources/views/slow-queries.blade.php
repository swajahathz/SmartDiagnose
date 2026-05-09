<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} — Slow queries</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    @include('partials.monitor-styles')
</head>
<body>
@include('partials.monitor-nav', ['active' => 'slow', 'pollLabel' => $pollLabel, 'authEnabled' => $authEnabled])

<div class="container-fluid px-3 pb-5">
    <div id="banner-error" class="alert alert-danger d-none" role="alert"></div>

    <p class="text-secondary small mb-3">
        <strong>Running slow</strong> = threads with command Query/Execute and <code>Time</code> ≥ server <code>long_query_time</code>.
        <strong>Digest</strong> = <code>performance_schema</code> totals (since stats reset).
        <strong>Log file / table</strong> = only if slow log is enabled (file or TABLE).
        Last: <span id="last-ts">—</span>
    </p>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Running slow queries (<span id="lqt-label">—</span>s threshold)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                            <tr>
                                <th>Id</th><th>User</th><th>DB</th><th class="text-end">Time</th><th>State</th><th>SQL</th>
                            </tr>
                            </thead>
                            <tbody id="tb-run"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Top digests (cumulative time)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                            <tr>
                                <th>Schema</th><th>Execs</th><th>Total(s)</th><th>Rows exam.</th><th>Digest</th>
                            </tr>
                            </thead>
                            <tbody id="tb-dig"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between flex-wrap gap-1">
            <span>Slow query log (file tail)</span>
            <small class="text-secondary text-truncate" style="max-width:70%" id="slow-file-path"></small>
        </div>
        <div class="card-body">
            <small class="text-secondary d-block mb-2" id="slow-file-err"></small>
            <pre class="log-box mb-0" id="slow-file-lines"></pre>
        </div>
    </div>

    <div class="card">
        <div class="card-header">mysql.slow_log table (when log_output=TABLE)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" id="tb-slowtbl">
                    <thead id="tb-slowtbl-head"><tr><th class="text-secondary px-3 py-3">—</th></tr></thead>
                    <tbody id="tb-slowtbl-body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
<script>
(function () {
    const pollMs = {{ $pollMs }};
    const banner = document.getElementById('banner-error');

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function renderRunning(rows, lqt) {
        document.getElementById('lqt-label').textContent = (lqt !== undefined && lqt !== null) ? String(lqt) : '—';
        const tb = document.getElementById('tb-run');
        if (!rows || !rows.length) {
            tb.innerHTML = '<tr><td colspan="6" class="text-secondary px-3 py-3">None above threshold right now</td></tr>';
            return;
        }
        tb.innerHTML = rows.map(r => '<tr class="'+(r.long_running?'long-query':'')+'">'+
            ['Id','User','db'].map(k => '<td class="small">'+escapeHtml(String(r[k]??''))+'</td>').join('')+
            '<td class="small text-end">'+escapeHtml(String(r.Time??''))+'</td>'+
            '<td class="small">'+escapeHtml(String(r.State??''))+'</td>'+
            '<td class="query-cell small">'+((r.Info!=null)?escapeHtml(String(r.Info)):'<span class="text-secondary">—</span>')+'</td>'+
            '</tr>').join('');
    }

    function renderDigests(rows) {
        const tb = document.getElementById('tb-dig');
        if (!rows || !rows.length) {
            tb.innerHTML = '<tr><td colspan="5" class="text-secondary px-3 py-3">No digest data (performance_schema / consumer)</td></tr>';
            return;
        }
        tb.innerHTML = rows.map(r => '<tr>'+
            '<td class="small">'+escapeHtml(String(r.schema_name??''))+'</td>'+
            '<td class="small">'+escapeHtml(String(r.count_star??''))+'</td>'+
            '<td class="small">'+Number(r.total_seconds||0).toFixed(3)+'</td>'+
            '<td class="small">'+escapeHtml(String(r.sum_rows_examined??''))+'</td>'+
            '<td class="query-cell small">'+escapeHtml(String(r.digest_text??''))+'</td>'+
            '</tr>').join('');
    }

    function renderSlowFile(el) {
        document.getElementById('slow-file-path').textContent = (el && el.path) ? el.path : '';
        document.getElementById('slow-file-err').textContent = (el && el.error) ? el.error : '';
        const lines = (el && el.lines) ? el.lines : [];
        document.getElementById('slow-file-lines').textContent = lines.length ? lines.join('\n') : '(no lines)';
    }

    function preferCols(keys) {
        const order = ['start_time','user_host','db','query_time','lock_time','rows_sent','rows_examined','sql_text'];
        const rest = keys.filter(k => order.indexOf(k) === -1).sort();
        return order.filter(k => keys.indexOf(k) !== -1).concat(rest);
    }

    function renderSlowTable(rows) {
        const head = document.getElementById('tb-slowtbl-head');
        const body = document.getElementById('tb-slowtbl-body');
        if (!rows || !rows.length) {
            head.innerHTML = '<tr><th class="text-secondary px-3 py-3 fw-normal">No table data (log not TABLE or no rights)</th></tr>';
            body.innerHTML = '';
            return;
        }
        const keys = preferCols(Object.keys(rows[0]));
        head.innerHTML = '<tr>'+keys.map(k => '<th class="small">'+escapeHtml(k)+'</th>').join('')+'</tr>';
        body.innerHTML = rows.map(row => '<tr>'+keys.map(k => {
            let v = row[k];
            if (v !== null && typeof v === 'object') v = JSON.stringify(v);
            const s = (v === undefined || v === null) ? '' : String(v);
            const cls = (k === 'sql_text') ? 'query-cell small' : 'small';
            return '<td class="'+cls+'">'+escapeHtml(s)+'</td>';
        }).join('')+'</tr>').join('');
    }

    async function tick() {
        try {
            const res = await fetch('{{ url('/api/slow-queries') }}', { credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            document.getElementById('last-ts').textContent = data.ts || '—';
            if (!data.ok) {
                banner.textContent = data.error || 'Error';
                banner.classList.remove('d-none');
                return;
            }
            banner.classList.add('d-none');
            renderRunning(data.running_slow || [], data.long_query_time);
            renderDigests(data.digests || []);
            renderSlowFile(data.slow_log_tail || {});
            renderSlowTable(data.slow_log_table || []);
        } catch (e) {
            banner.textContent = 'Request failed: ' + e.message;
            banner.classList.remove('d-none');
        }
    }

    tick();
    setInterval(tick, pollMs);
})();
</script>
</body>
</html>
