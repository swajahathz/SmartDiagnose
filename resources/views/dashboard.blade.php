<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} — MySQL Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    @include('partials.monitor-styles')
</head>
<body>
@include('partials.monitor-nav', [
    'active' => 'dashboard',
    'pollLabel' => 'Poll: '.$pollSeconds.'s',
])

<div class="container-fluid px-3 pb-5">
    <div id="banner-error" class="alert alert-danger d-none" role="alert"></div>

    <section id="health-card" class="health-card health-card--good mb-3" role="status" aria-live="polite" aria-label="Realtime health summary">
        <div class="d-flex gap-3 align-items-center justify-content-between flex-wrap">
            <div class="d-flex gap-3 align-items-center flex-grow-1 min-w-0">
                <span class="health-dot flex-shrink-0" aria-hidden="true"></span>
                <div class="health-card__title mb-0" id="health-title">Loading…</div>
            </div>
            <button type="button" class="btn btn-sm health-toggle-btn d-inline-flex align-items-center gap-1 flex-shrink-0"
                    id="health-toggle-btn" data-bs-toggle="collapse" data-bs-target="#health-collapse"
                    aria-expanded="false" aria-controls="health-collapse">
                <span id="health-toggle-label">Details</span>
                <span class="health-toggle-chevron" aria-hidden="true">▾</span>
            </button>
        </div>
        <div class="collapse" id="health-collapse">
            <p class="health-card__blurb mb-0 mt-3 pt-2 border-top border-secondary border-opacity-25" id="health-blurb">Waiting for metrics.</p>
            <ul class="health-items list-unstyled mb-0 mt-2" id="health-items"></ul>
        </div>
    </section>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card h-100">
                <div class="card-body">
                    <div class="metric-label">Load (1m)</div>
                    <div class="metric-value" id="m-load-1">—</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card h-100">
                <div class="card-body">
                    <div class="metric-label">Load (5m / 15m)</div>
                    <div class="metric-value fs-4" id="m-load-515">—</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card h-100">
                <div class="card-body">
                    <div class="metric-label">CPU</div>
                    <div class="metric-value"><span id="m-cpu">—</span><span class="fs-5 text-secondary">%</span></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card h-100">
                <div class="card-body">
                    <div class="metric-label">RAM used</div>
                    <div class="metric-value"><span id="m-mem">—</span><span class="fs-5 text-secondary">%</span></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card h-100">
                <div class="card-body">
                    <div class="metric-label">Queries / sec</div>
                    <div class="metric-value" id="m-qps">—</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card h-100">
                <div class="card-body">
                    <div class="metric-label">Threads / slow</div>
                    <div class="metric-value fs-5"><span id="m-threads">—</span> <span class="text-secondary">/</span> <span id="m-slowtot">—</span></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card h-100 disk-dash-shell" id="disk-dash-shell">
                <div class="card-body">
                    <div class="metric-label">Disks (<code>iostat</code>)</div>
                    <div class="mb-1"><span class="badge rounded-pill disk-dash-badge disk-level-unknown" id="disk-dash-badge">Sampling…</span></div>
                    <div class="small text-secondary lh-sm" id="disk-dash-detail">—</div>
                    <a href="{{ route('drives') }}" class="small link-light link-underline-opacity-50 link-underline-opacity-100-hover d-inline-block mt-1">Drive dashboard →</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">Query load (QPS)</div>
                <div class="card-body chart-wrap">
                    <canvas id="chart-qps" aria-label="QPS chart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">Server CPU %</div>
                <div class="card-body chart-wrap">
                    <canvas id="chart-cpu" aria-label="CPU chart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>MySQL overview</span>
                    <small class="text-secondary" id="mysql-version">—</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <tbody id="tbl-status"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">Key variables</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <tbody id="tbl-vars"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="replica-card" class="card mb-3 d-none">
        <div class="card-header">Replication</div>
        <div class="card-body">
            <pre class="mb-0 text-secondary small" id="replica-pre"></pre>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Process list</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Id</th>
                        <th>User</th>
                        <th>Host</th>
                        <th>DB</th>
                        <th>Command</th>
                        <th>Time</th>
                        <th>State</th>
                        <th>Info</th>
                    </tr>
                    </thead>
                    <tbody id="tbl-proc"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Top statements (performance_schema)</span>
            <small class="text-secondary">By total wait time — enable performance_schema + consumer if empty</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Schema</th>
                        <th>Count</th>
                        <th>Total time (s)</th>
                        <th>Rows examined</th>
                        <th>Digest</th>
                    </tr>
                    </thead>
                    <tbody id="tbl-slow"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>MySQL error log (tail)</span>
            <small class="text-secondary text-truncate" style="max-width: 60%" id="log-path"></small>
        </div>
        <div class="card-body">
            <pre class="log-box mb-0" id="log-lines"></pre>
            <small class="text-secondary mt-1 d-block" id="log-err"></small>
        </div>
    </div>

    <p class="text-secondary small mt-4 mb-0">
        Server metrics from Linux <code>/proc</code>; disk summary from <code>iostat</code>
        (~{{ (int) config('monitor.iostat_interval', 1) }}s sample every {{ (float) ($diskPollSeconds ?? 5) }}s). MySQL metrics use
        <code>SHOW GLOBAL STATUS</code> and <code>performance_schema</code>. Last update:
        <span id="last-ts">—</span>
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
(function () {
    const pollMs = {{ (int) $pollSeconds }} * 1000;
    const diskPollMs = {{ max(3000, (float) ($diskPollSeconds ?? 5) * 1000) }};
    const diskApiUrl = @json(route('api.drive-status'));
    const fmt = (v) => (v === null || v === undefined || Number.isNaN(v)) ? '—' : v;
    const historyLen = 36;
    const labels = Array.from({length: historyLen}, (_, i) => '');

    function pushSeries(arr, val) {
        arr.push(val);
        if (arr.length > historyLen) arr.shift();
    }

    const qpsHist = [];
    const cpuHist = [];

    const chartCommon = {
        type: 'line',
        data: { labels: [...labels], datasets: [] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            scales: {
                x: { display: false },
                y: { beginAtZero: true, grid: { color: '#30363d' }, ticks: { color: '#8b949e' } },
            },
            plugins: { legend: { display: false } },
        },
    };

    const ctxQ = document.getElementById('chart-qps');
    const ctxC = document.getElementById('chart-cpu');

    const chartQ = new Chart(ctxQ, {
        ...chartCommon,
        data: {
            labels: [...labels],
            datasets: [{
                label: 'QPS',
                data: [...qpsHist],
                borderColor: '#58a6ff',
                backgroundColor: 'rgba(88, 166, 255, .15)',
                fill: true,
                tension: 0.25,
                borderWidth: 2,
                pointRadius: 0,
            }],
        },
    });

    const chartC = new Chart(ctxC, {
        ...chartCommon,
        data: {
            labels: [...labels],
            datasets: [{
                label: 'CPU',
                data: [...cpuHist],
                borderColor: '#3fb950',
                backgroundColor: 'rgba(63, 185, 80, .12)',
                fill: true,
                tension: 0.25,
                borderWidth: 2,
                pointRadius: 0,
            }],
        },
        options: {
            ...chartCommon.options,
            scales: {
                x: { display: false },
                y: { min: 0, max: 100, grid: { color: '#30363d' }, ticks: { color: '#8b949e' } },
            },
        },
    });

    function rowHtml(cells) {
        return '<tr>' + cells.map(c => '<td>' + c + '</td>').join('') + '</tr>';
    }

    function renderStatus(st) {
        const rows = [
            ['Uptime', formatUptime(st.Uptime)],
            ['Questions / Queries', (st.Queries || st.Questions || '—')],
            ['Threads connected', st.Threads_connected],
            ['Threads running', st.Threads_running],
            ['Max used connections', st.Max_used_connections],
            ['Aborted connects', st.Aborted_connects],
            ['Bytes recv / sent', formatBytes(st.Bytes_received) + ' / ' + formatBytes(st.Bytes_sent)],
            ['Table locks waited', st.Table_locks_waited],
            ['InnoDB buffer pool reads / requests', (st.Innodb_buffer_pool_reads || '0') + ' / ' + (st.Innodb_buffer_pool_read_requests || '0')],
        ];
        document.getElementById('tbl-status').innerHTML = rows.map(r => rowHtml(r)).join('');
    }

    function formatUptime(sec) {
        const s = parseInt(sec, 10);
        if (!s) return '—';
        const d = Math.floor(s / 86400);
        const h = Math.floor((s % 86400) / 3600);
        const m = Math.floor((s % 3600) / 60);
        return d + 'd ' + h + 'h ' + m + 'm';
    }

    function formatBytes(v) {
        const n = parseFloat(v, 10);
        if (isNaN(n)) return '—';
        if (n < 1024) return n + ' B';
        const u = ['KB','MB','GB','TB'];
        let x = n / 1024; let i = 0;
        while (x >= 1024 && i < u.length - 1) { x /= 1024; i++; }
        return x.toFixed(1) + ' ' + u[i];
    }

    function renderVars(v) {
        const keys = Object.keys(v || {}).sort();
        document.getElementById('tbl-vars').innerHTML = keys.map(k =>
            rowHtml([k, escapeHtml(String(v[k]))])
        ).join('');
        document.getElementById('mysql-version').textContent =
            (v.version || '') + (v.version_comment ? ' — ' + v.version_comment : '');
    }

    function escapeHtml(s) {
        return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function renderDiskBanner(d) {
        const badge = document.getElementById('disk-dash-badge');
        const detail = document.getElementById('disk-dash-detail');
        const shell = document.getElementById('disk-dash-shell');
        if (!badge || !detail || !shell) return;

        badge.classList.remove(
            'disk-level-good','disk-level-warning','disk-level-critical','disk-level-unknown'
        );
        shell.classList.remove('disk-dash-good','disk-dash-warn','disk-dash-critical','disk-dash-unknown');

        if (!d || d.ok !== true) {
            const msg = (d && d.error) ? d.error : 'No data';
            badge.textContent = 'N/A';
            badge.classList.add('disk-level-unknown');
            shell.classList.add('disk-dash-unknown');
            detail.textContent = typeof msg === 'string' ? msg.slice(0, 140) + (msg.length > 140 ? '…' : '') : msg;
            return;
        }

        const s = d.summary || {};
        const lvl = ['good','warning','critical'].includes(s.level) ? s.level : 'unknown';

        badge.classList.add(lvl === 'unknown' ? 'disk-level-unknown' : ('disk-level-' + lvl));
        badge.textContent = lvl === 'good' ? 'GOOD' : (lvl === 'warning' ? 'BUSY' : (lvl === 'critical' ? 'HOT' : 'N/A'));
        shell.classList.add({
            good: 'disk-dash-good',
            warning: 'disk-dash-warn',
            critical: 'disk-dash-critical',
        }[lvl] || 'disk-dash-unknown');

        const maxU = s.max_util_percent;
        const md = s.max_util_device ? String(s.max_util_device) + ' ' : '';
        const iw = s.iowait_percent != null ? s.iowait_percent : (d.cpu && d.cpu.iowait != null ? d.cpu.iowait : null);
        const parts = [];
        if (maxU != null) parts.push(md + Number(maxU).toFixed(0) + '% util peak');
        if (iw != null) parts.push(Number(iw).toFixed(1) + '% iowait');
        if (parts.length) {
            detail.textContent = parts.join(' · ');
        } else if (s.detail) {
            const t = String(s.detail);
            detail.textContent = t.length > 160 ? t.slice(0, 157) + '…' : t;
        } else {
            detail.textContent = 'Awaiting rollup.';
        }
    }

    async function diskTick() {
        try {
            const res = await fetch(diskApiUrl, { credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const d = await res.json();
            renderDiskBanner(d);
        } catch (e) {
            renderDiskBanner({ ok: false, error: e.message });
        }
    }

    function renderHealth(h) {
        const card = document.getElementById('health-card');
        const titleEl = document.getElementById('health-title');
        const blurbEl = document.getElementById('health-blurb');
        const listEl = document.getElementById('health-items');
        const toggleBtn = document.getElementById('health-toggle-btn');
        const toggleLabel = document.getElementById('health-toggle-label');
        if (!h || typeof h.level !== 'string') {
            card.classList.remove('health-card--good', 'health-card--warning', 'health-card--critical');
            card.classList.add('health-card--good');
            titleEl.textContent = 'Health unavailable';
            blurbEl.textContent = 'No health payload returned from /api/metrics.';
            listEl.innerHTML = '';
            if (toggleLabel) toggleLabel.textContent = 'Details';
            if (toggleBtn) toggleBtn.disabled = false;
            return;
        }
        card.classList.remove('health-card--good', 'health-card--warning', 'health-card--critical');
        const lvl = ['good', 'warning', 'critical'].includes(h.level) ? h.level : 'good';
        card.classList.add('health-card--' + lvl);
        titleEl.textContent = h.title || '';
        blurbEl.textContent = h.blurb || '';
        const items = Array.isArray(h.items) ? h.items : [];
        listEl.innerHTML = items.map(function (it) {
            const tier = ['good', 'warning', 'critical'].includes(it.tier) ? it.tier : 'good';
            return '<li class="health-item health-item--' + tier + '">'
                + '<strong class="health-item__label">' + escapeHtml(it.label || '') + '</strong>'
                + '<span class="health-item__detail">' + escapeHtml(it.detail || '') + '</span>'
                + '</li>';
        }).join('');
        if (toggleLabel) {
            toggleLabel.textContent = items.length ? ('Details (' + items.length + ')') : 'Details';
        }
        if (toggleBtn) {
            toggleBtn.disabled = items.length === 0 && !(h.blurb && String(h.blurb).trim());
        }
    }

    function renderProc(rows) {
        const tb = document.getElementById('tbl-proc');
        if (!rows || !rows.length) {
            tb.innerHTML = '<tr><td colspan="8" class="text-secondary px-3 py-4">No rows</td></tr>';
            return;
        }
        tb.innerHTML = rows.map(r => {
            const cls = r.long_running ? ' class="long-query"' : '';
            return '<tr' + cls + '>' + [
                r.Id, r.User, r.Host, r.db ?? '', r.Command, r.Time, escapeHtml(String(r.State ?? '')),
                escapeHtml(String(r.Info ?? '')).slice(0, 400),
            ].map(c => '<td class="small">' + (c === null ? '' : c) + '</td>').join('') + '</tr>';
        }).join('');
    }

    function renderSlow(rows) {
        const tb = document.getElementById('tbl-slow');
        if (!rows || !rows.length) {
            tb.innerHTML = '<tr><td colspan="5" class="text-secondary px-3 py-4">No digest data (or performance_schema empty)</td></tr>';
            return;
        }
        tb.innerHTML = rows.map(r => rowHtml([
            escapeHtml(String(r.schema_name ?? '')),
            r.count_star,
            Number(r.total_seconds || 0).toFixed(3),
            r.sum_rows_examined,
            '<span class="small">' + escapeHtml(r.digest_text || '') + '</span>',
        ])).join('');
    }

    function renderReplica(rep) {
        const card = document.getElementById('replica-card');
        const pre = document.getElementById('replica-pre');
        if (!rep || !Object.keys(rep).length) {
            card.classList.add('d-none');
            return;
        }
        card.classList.remove('d-none');
        pre.textContent = JSON.stringify(rep, null, 2);
    }

    function renderLog(el) {
        const pathEl = document.getElementById('log-path');
        const linesEl = document.getElementById('log-lines');
        const errEl = document.getElementById('log-err');
        pathEl.textContent = el.path ? el.path : '';
        errEl.textContent = el.error || '';
        linesEl.textContent = (el.lines && el.lines.length) ? el.lines.join('\n') : '(no lines)';
    }

    async function tick() {
        const banner = document.getElementById('banner-error');
        try {
            const res = await fetch('{{ url('/api/metrics') }}', { credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            document.getElementById('last-ts').textContent = data.ts || '—';
            banner.classList.add('d-none');
            renderHealth(data.health);

            const srv = data.server || {};
            const load = srv.load;
            document.getElementById('m-load-1').textContent = load ? fmt(load['1m'].toFixed(2)) : '—';
            document.getElementById('m-load-515').textContent = load
                ? load['5m'].toFixed(2) + ' / ' + load['15m'].toFixed(2)
                : '—';
            document.getElementById('m-cpu').textContent = srv.cpu_percent != null ? srv.cpu_percent.toFixed(1) : '—';
            const mem = srv.memory;
            document.getElementById('m-mem').textContent = mem ? mem.used_percent.toFixed(1) : '—';

            pushSeries(cpuHist, srv.cpu_percent != null ? srv.cpu_percent : 0);
            chartC.data.datasets[0].data = [...cpuHist];
            chartC.update('none');

            if (!data.ok) {
                banner.textContent = 'MySQL: ' + (data.mysql_error || 'connection error');
                banner.classList.remove('d-none');
                return;
            }

            const m = data.mysql;
            const st = m.status || {};
            const qps = m.queries_per_sec ?? 0;
            document.getElementById('m-qps').textContent = fmt(qps);
            document.getElementById('m-threads').textContent = st.Threads_connected ?? '—';
            document.getElementById('m-slowtot').textContent = st.Slow_queries ?? '—';

            pushSeries(qpsHist, typeof qps === 'number' ? qps : 0);
            chartQ.data.datasets[0].data = [...qpsHist];
            chartQ.update('none');

            renderStatus(st);
            renderVars(m.variables || {});
            renderProc(m.processlist || []);
            renderSlow(m.slow_digests || []);
            renderReplica(m.replica);
            renderLog(m.error_log || {});
        } catch (e) {
            banner.textContent = 'Request failed: ' + e.message;
            banner.classList.remove('d-none');
        }
    }

    tick();
    setInterval(tick, pollMs);
    diskTick();
    setInterval(diskTick, diskPollMs);
})();
</script>
</body>
</html>
