<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} — Drive status</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    @include('partials.monitor-styles')
</head>
<body>
@include('partials.monitor-nav', [
    'active' => 'drives',
    'pollLabel' => 'Poll: '.$pollSeconds.'s (iostat+pidstat ~'.(int) config('monitor.iostat_interval', 1).'s)',
])

<div class="container-fluid px-3 pb-5">
    <div id="banner-error" class="alert alert-danger d-none" role="alert"></div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6 col-xl-3">
            <div class="card h-100" id="disk-main-card">
                <div class="card-body">
                    <div class="metric-label mb-2">Overall block IO</div>
                    <div class="fs-4 fw-semibold mb-2"><span id="disk-headline" class="disk-level-text disk-level-unknown">Loading…</span></div>
                    <p class="small text-secondary mb-0 lh-base" id="disk-detail"></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card h-100">
                <div class="card-body">
                    <div class="metric-label">Peak %util</div>
                    <div class="metric-value" id="m-d-maxu">—</div>
                    <small class="text-secondary" id="m-d-maxd"></small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card h-100">
                <div class="card-body">
                    <div class="metric-label">Top IO service</div>
                    <div class="metric-value fs-5 text-truncate" id="m-d-topsvc">—</div>
                    <small class="text-secondary" id="m-d-topio"></small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card h-100">
                <div class="card-body">
                    <div class="metric-label">CPU iowait</div>
                    <div class="metric-value"><span id="m-d-iow">—</span><span class="fs-6 text-secondary">%</span></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card h-100">
                <div class="card-body">
                    <div class="metric-label">Sample latency</div>
                    <div class="metric-value fs-4"><span id="m-d-ms">—</span><small class="fs-6 text-secondary"> ms</small></div>
                    <small class="text-secondary" id="m-d-ts"></small>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">Peak device % utilization (rollup set)</div>
                <div class="card-body chart-wrap">
                    <canvas id="chart-util" aria-label="Utilization chart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">CPU iowait %</div>
                <div class="card-body chart-wrap">
                    <canvas id="chart-iowait" aria-label="IO Wait chart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span>Top services by disk IO <span class="text-secondary fw-normal">(click a row for query detail)</span></span>
            <small class="text-secondary" id="proc-meta"></small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Service</th>
                        <th>Command</th>
                        <th class="text-end">PID</th>
                        <th class="text-end">Read kB/s</th>
                        <th class="text-end">Write kB/s</th>
                        <th class="text-end">Total kB/s</th>
                        <th class="text-end">iodelay</th>
                    </tr>
                    </thead>
                    <tbody id="tbl-processes"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Per-device snapshot (same iostat interval)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Device</th>
                        <th class="text-end">r/s</th>
                        <th class="text-end">rkB/s</th>
                        <th class="text-end">w/s</th>
                        <th class="text-end">wkB/s</th>
                        <th class="text-end">aqu-sz</th>
                        <th class="text-end">%util</th>
                        <th>Rollup</th>
                    </tr>
                    </thead>
                    <tbody id="tbl-devices"></tbody>
                </table>
            </div>
        </div>
    </div>

    <p class="text-secondary small mt-4 mb-0">
        Device data: <code>iostat -y -x {{ (int) config('monitor.iostat_interval', 1) }} 1</code>;
        process IO: <code>pidstat -d {{ (int) config('monitor.iostat_interval', 1) }} 1</code> (sysstat, parallel).
        Loop/sr devices are hidden from peak rollup.
        Last update: <span id="last-ts">—</span>
    </p>
</div>

{{-- Service detail (queries / logs) --}}
<div class="offcanvas offcanvas-end text-bg-dark" tabindex="-1" id="svc-detail" aria-labelledby="svc-detail-title" style="width: min(720px, 100vw)">
    <div class="offcanvas-header border-bottom border-secondary">
        <div>
            <h5 class="offcanvas-title mb-0" id="svc-detail-title">Service detail</h5>
            <small class="text-secondary" id="svc-detail-sub"></small>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <p class="small text-secondary" id="svc-detail-hint"></p>
        <div id="svc-detail-body">
            <p class="text-secondary">Select a service row…</p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
(function () {
    const pollMs = {{ (float) $pollSeconds }} * 1000;
    const apiUrl = @json(route('api.drive-status'));
    const detailUrl = @json(route('api.drive-service-detail'));
    const historyLen = 60;
    const labels = Array.from({length: historyLen}, () => '');
    let inFlight = false;
    let timer = null;
    let detailTimer = null;
    let selectedSvc = null;
    const svcCanvas = document.getElementById('svc-detail');
    const svcOffcanvas = bootstrap.Offcanvas.getOrCreateInstance(svcCanvas);

    function pushSeries(arr, val) {
        arr.push(val);
        if (arr.length > historyLen) arr.shift();
    }

    const utilHist = [];
    const ioWaitHist = [];

    const chartCommon = {
        type: 'line',
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            scales: {
                x: { display: false },
                y: { beginAtZero: true, suggestedMax: 100, grid: { color: '#30363d' }, ticks: { color: '#8b949e' } },
            },
            plugins: { legend: { display: false } },
        },
    };

    const chartUtil = new Chart(document.getElementById('chart-util'), {
        ...chartCommon,
        data: {
            labels: [...labels],
            datasets: [{
                label: '%util peak',
                data: [...utilHist],
                borderColor: '#a371f7',
                backgroundColor: 'rgba(163, 113, 247, .12)',
                fill: true,
                tension: 0.22,
                borderWidth: 2,
                pointRadius: 0,
            }],
        },
    });

    const chartIoWait = new Chart(document.getElementById('chart-iowait'), {
        ...chartCommon,
        data: {
            labels: [...labels],
            datasets: [{
                label: 'iowait',
                data: [...ioWaitHist],
                borderColor: '#d29922',
                backgroundColor: 'rgba(210, 153, 34, .12)',
                fill: true,
                tension: 0.22,
                borderWidth: 2,
                pointRadius: 0,
            }],
        },
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function fmtRate(v) {
        if (v == null || Number.isNaN(Number(v))) return '—';
        const n = Number(v);
        if (n >= 1024) return (n / 1024).toFixed(2) + ' MB';
        return n.toFixed(1);
    }

    function diskLevelUi(level) {
        const main = document.getElementById('disk-main-card');
        main.classList.remove('disk-border-good', 'disk-border-warn', 'disk-border-critical', 'disk-border-muted');
        if (level === 'good') main.classList.add('disk-border-good');
        else if (level === 'warning') main.classList.add('disk-border-warn');
        else if (level === 'critical') main.classList.add('disk-border-critical');
        else main.classList.add('disk-border-muted');
    }

    function renderDevices(rows) {
        const tb = document.getElementById('tbl-devices');
        if (!rows || !rows.length) {
            tb.innerHTML = '<tr><td colspan="8" class="text-secondary px-3 py-4">No devices</td></tr>';
            return;
        }
        tb.innerHTML = rows.map(r => {
            const rollup = r.counts_for_peak ? '<span class="badge rounded-pill disk-pill-yes">Yes</span>' : '<span class="badge rounded-pill disk-pill-no">No</span>';
            return '<tr>'
                + '<td class="font-monospace">' + escapeHtml(r.device || '') + '</td>'
                + '<td class="text-end">' + (r.r_per_s ?? '—') + '</td>'
                + '<td class="text-end">' + (r.rkB_per_s ?? '—') + '</td>'
                + '<td class="text-end">' + (r.w_per_s ?? '—') + '</td>'
                + '<td class="text-end">' + (r.wkB_per_s ?? '—') + '</td>'
                + '<td class="text-end">' + (r.aqu_sz ?? '—') + '</td>'
                + '<td class="text-end fw-semibold">' + (r.util_percent ?? '—') + '</td>'
                + '<td>' + rollup + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderProcesses(rows, meta) {
        const tb = document.getElementById('tbl-processes');
        const metaEl = document.getElementById('proc-meta');
        if (metaEl) metaEl.textContent = meta || '';

        if (!rows || !rows.length) {
            tb.innerHTML = '<tr><td colspan="7" class="text-secondary px-3 py-4">No process IO in this sample (idle or pidstat unavailable)</td></tr>';
            return;
        }

        const maxTotal = Math.max(...rows.map(r => Number(r.total_kB_per_s) || 0), 1);

        tb.innerHTML = rows.map((r, idx) => {
            const total = Number(r.total_kB_per_s) || 0;
            const hot = idx === 0 && total > 0;
            const barPct = Math.min(100, Math.round((total / maxTotal) * 100));
            const svc = r.service || r.command || '';
            const selected = selectedSvc && String(selectedSvc.pid) === String(r.pid);
            const cls = ['disk-svc-row', hot ? 'table-danger' : '', selected ? 'disk-svc-selected' : ''].filter(Boolean).join(' ');
            return '<tr class="' + cls + '" role="button" tabindex="0" title="Click for query / detail"'
                + ' data-service="' + escapeHtml(svc) + '"'
                + ' data-command="' + escapeHtml(r.command || '') + '"'
                + ' data-pid="' + (r.pid ?? '') + '">'
                + '<td class="fw-semibold">' + escapeHtml(svc)
                + ' <span class="text-secondary small">↗</span></td>'
                + '<td class="font-monospace small text-secondary">' + escapeHtml(r.command || '') + '</td>'
                + '<td class="text-end font-monospace">' + (r.pid ?? '—') + '</td>'
                + '<td class="text-end">' + fmtRate(r.kB_rd_per_s) + '</td>'
                + '<td class="text-end fw-semibold">' + fmtRate(r.kB_wr_per_s) + '</td>'
                + '<td class="text-end">'
                + '<div class="d-flex align-items-center justify-content-end gap-2">'
                + '<span>' + fmtRate(r.total_kB_per_s) + '</span>'
                + '<span class="disk-io-bar" title="' + barPct + '% of top"><span style="width:' + barPct + '%"></span></span>'
                + '</div></td>'
                + '<td class="text-end">' + (r.iodelay ?? '—') + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderQueryTable(rows, emptyMsg) {
        if (!rows || !rows.length) {
            return '<p class="text-secondary small mb-0">' + escapeHtml(emptyMsg || 'None') + '</p>';
        }
        return '<div class="table-responsive"><table class="table table-sm table-hover mb-0">'
            + '<thead><tr><th>Id</th><th>User</th><th>DB</th><th class="text-end">Time</th><th>State</th><th>Query</th></tr></thead><tbody>'
            + rows.map(r => {
                const cls = r.long_running ? 'long-query' : '';
                const q = r.Info != null ? escapeHtml(String(r.Info)) : '<span class="text-secondary">—</span>';
                return '<tr class="' + cls + '">'
                    + '<td class="font-monospace">' + escapeHtml(String(r.Id ?? '—')) + '</td>'
                    + '<td>' + escapeHtml(String(r.User ?? '—')) + '</td>'
                    + '<td>' + escapeHtml(String(r.db ?? '—')) + '</td>'
                    + '<td class="text-end">' + (r.Time != null ? Number(r.Time).toFixed(0) + 's' : '—') + '</td>'
                    + '<td class="small">' + escapeHtml(String(r.State ?? '—')) + '</td>'
                    + '<td class="sql-wrap small font-monospace">' + q + '</td>'
                    + '</tr>';
            }).join('')
            + '</tbody></table></div>';
    }

    function renderCurrentStatements(rows) {
        if (!rows || !rows.length) return '';
        return '<h6 class="mt-3 mb-2">Currently executing (performance_schema)</h6>'
            + '<div class="table-responsive"><table class="table table-sm table-hover mb-0">'
            + '<thead><tr><th>Thread</th><th>Schema</th><th class="text-end">Sec</th><th class="text-end">Rows exam</th><th>SQL</th></tr></thead><tbody>'
            + rows.map(r => '<tr>'
                + '<td class="font-monospace">' + escapeHtml(String(r.thread_id ?? '—')) + '</td>'
                + '<td>' + escapeHtml(String(r.schema ?? '—')) + '</td>'
                + '<td class="text-end">' + (r.seconds != null ? Number(r.seconds).toFixed(3) : '—') + '</td>'
                + '<td class="text-end">' + (r.rows_examined ?? '—') + '</td>'
                + '<td class="sql-wrap small font-monospace">' + escapeHtml(String(r.sql_text || '')) + '</td>'
                + '</tr>').join('')
            + '</tbody></table></div>';
    }

    function renderDigests(rows) {
        if (!rows || !rows.length) return '';
        return '<h6 class="mt-3 mb-2">Top digests (cumulative wait)</h6>'
            + '<div class="table-responsive"><table class="table table-sm table-hover mb-0">'
            + '<thead><tr><th>Schema</th><th class="text-end">Calls</th><th class="text-end">Total s</th><th>Digest</th></tr></thead><tbody>'
            + rows.map(r => '<tr>'
                + '<td>' + escapeHtml(String(r.schema_name ?? '—')) + '</td>'
                + '<td class="text-end">' + (r.count_star ?? '—') + '</td>'
                + '<td class="text-end">' + (r.total_seconds != null ? Number(r.total_seconds).toFixed(2) : '—') + '</td>'
                + '<td class="sql-wrap small font-monospace">' + escapeHtml(String(r.digest_text || '')) + '</td>'
                + '</tr>').join('')
            + '</tbody></table></div>';
    }

    function renderDetailPayload(d) {
        const title = document.getElementById('svc-detail-title');
        const sub = document.getElementById('svc-detail-sub');
        const hint = document.getElementById('svc-detail-hint');
        const body = document.getElementById('svc-detail-body');

        title.textContent = (d.service || 'Service') + ' detail';
        sub.textContent = [d.command, d.pid ? ('PID ' + d.pid) : '', d.ts].filter(Boolean).join(' · ');

        const summary = d.summary || {};
        hint.textContent = summary.hint || '';

        if (!d.ok) {
            body.innerHTML = '<div class="alert alert-danger mb-0">' + escapeHtml(d.error || 'Failed') + '</div>';
            return;
        }

        let html = '';
        if (d.kind === 'mysql') {
            const s = summary;
            html += '<div class="d-flex flex-wrap gap-2 mb-3 small">'
                + '<span class="badge text-bg-secondary">threads ' + (s.threads_total ?? '—') + '</span>'
                + '<span class="badge text-bg-primary">active queries ' + (s.queries_active ?? '—') + '</span>'
                + '<span class="badge text-bg-dark">sleep ' + (s.sleeping ?? '—') + '</span>'
                + '</div>';
            html += '<h6 class="mb-2">Active queries (PROCESSLIST)</h6>';
            html += renderQueryTable(d.queries, 'No active SQL in PROCESSLIST right now');
            html += renderCurrentStatements(d.current_statements || []);
            html += renderDigests(d.digests || []);
            if ((d.other_threads || []).length) {
                html += '<h6 class="mt-3 mb-2">Other non-Sleep threads</h6>';
                html += renderQueryTable(d.other_threads, '');
            }
        } else if (d.kind === 'freeradius') {
            if (d.related && d.related.open_mysql) {
                html += '<button type="button" class="btn btn-sm btn-outline-info mb-3" id="btn-open-mysql">'
                    + escapeHtml(d.related.label || 'Open MySQL queries') + '</button>';
            }
            html += '<h6 class="mb-2">Recent FreeRADIUS log</h6>';
            const lines = d.log_lines || [];
            if (!lines.length) {
                html += '<p class="text-secondary small">' + escapeHtml(summary.log_error || 'No log lines') + '</p>';
            } else {
                html += '<pre class="disk-log-pre small mb-0">' + escapeHtml(lines.join('\n')) + '</pre>';
            }
        } else {
            html += '<p class="text-secondary small mb-0">No per-query breakdown for this process.</p>';
            html += '<p class="small mt-2 mb-0"><a class="link-light" href="' + @json(route('queries')) + '">Open Live queries →</a></p>';
        }

        body.innerHTML = html;

        const btn = document.getElementById('btn-open-mysql');
        if (btn) {
            btn.addEventListener('click', () => {
                selectedSvc = { service: 'MySQL', command: 'mysqld', pid: '' };
                loadServiceDetail(true);
            });
        }
    }

    async function loadServiceDetail(open) {
        if (!selectedSvc) return;
        const params = new URLSearchParams({
            service: selectedSvc.service || '',
            command: selectedSvc.command || '',
            pid: selectedSvc.pid || '',
        });
        try {
            const res = await fetch(detailUrl + '?' + params.toString(), { credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const d = await res.json();
            renderDetailPayload(d);
            if (open) svcOffcanvas.show();
        } catch (e) {
            document.getElementById('svc-detail-body').innerHTML =
                '<div class="alert alert-danger mb-0">Request failed: ' + escapeHtml(e.message) + '</div>';
            if (open) svcOffcanvas.show();
        }
    }

    function scheduleDetailRefresh() {
        if (detailTimer) clearTimeout(detailTimer);
        if (!selectedSvc || !svcCanvas.classList.contains('show')) return;
        detailTimer = setTimeout(async () => {
            await loadServiceDetail(false);
            scheduleDetailRefresh();
        }, 1500);
    }

    document.getElementById('tbl-processes').addEventListener('click', (ev) => {
        const tr = ev.target.closest('tr.disk-svc-row');
        if (!tr) return;
        selectedSvc = {
            service: tr.getAttribute('data-service') || '',
            command: tr.getAttribute('data-command') || '',
            pid: tr.getAttribute('data-pid') || '',
        };
        loadServiceDetail(true).then(scheduleDetailRefresh);
    });

    svcCanvas.addEventListener('hidden.bs.offcanvas', () => {
        if (detailTimer) clearTimeout(detailTimer);
        detailTimer = null;
        selectedSvc = null;
    });

    svcCanvas.addEventListener('shown.bs.offcanvas', scheduleDetailRefresh);

    async function tick() {
        if (inFlight) return;
        inFlight = true;
        const banner = document.getElementById('banner-error');
        try {
            const res = await fetch(apiUrl, {credentials: 'same-origin'});
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const d = await res.json();
            document.getElementById('last-ts').textContent = d.ts || '—';
            banner.classList.add('d-none');

            const s = d.summary || {};
            diskLevelUi(s.level || 'unknown');

            const hl = document.getElementById('disk-headline');
            hl.textContent = s.headline || (d.ok ? '' : 'Error');
            hl.classList.remove('disk-level-good', 'disk-level-warning', 'disk-level-critical', 'disk-level-unknown');
            const lvl = ['good','warning','critical'].includes(s.level) ? s.level : 'unknown';
            hl.classList.add('disk-level-' + lvl);

            document.getElementById('disk-detail').textContent = s.detail || d.error || '';

            const maxU = s.max_util_percent;
            document.getElementById('m-d-maxu').textContent = maxU != null ? Number(maxU).toFixed(1) : '—';
            document.getElementById('m-d-maxd').textContent = s.max_util_device ? 'worst ' + s.max_util_device : '';

            const procs = d.processes || [];
            const top = procs[0];
            document.getElementById('m-d-topsvc').textContent = top
                ? (top.service || top.command || '—')
                : (s.top_io_service || '—');
            document.getElementById('m-d-topio').textContent = top
                ? ('PID ' + top.pid + ' · R ' + fmtRate(top.kB_rd_per_s) + ' / W ' + fmtRate(top.kB_wr_per_s) + ' kB/s')
                : '';

            const iw = s.iowait_percent != null ? s.iowait_percent : (d.cpu && d.cpu.iowait != null ? d.cpu.iowait : null);
            document.getElementById('m-d-iow').textContent = iw != null ? Number(iw).toFixed(1) : '—';

            document.getElementById('m-d-ms').textContent = d.elapsed_ms != null ? d.elapsed_ms : '—';
            document.getElementById('m-d-ts').textContent = d.command ? d.command : '';

            const peakUtil = maxU != null ? Number(maxU) : 0;
            pushSeries(utilHist, peakUtil);
            chartUtil.data.datasets[0].data = [...utilHist];
            chartUtil.update('none');

            const iwVal = iw != null ? Number(iw) : 0;
            pushSeries(ioWaitHist, iwVal);
            chartIoWait.data.datasets[0].data = [...ioWaitHist];
            chartIoWait.update('none');

            const pio = d.process_io || {};
            let procMeta = procs.length + ' active';
            if (pio.ok === false && pio.error) procMeta = 'pidstat: ' + pio.error;
            renderProcesses(procs, procMeta);
            renderDevices(d.devices || []);
        } catch (e) {
            banner.textContent = 'Request failed: ' + e.message;
            banner.classList.remove('d-none');
        } finally {
            inFlight = false;
            if (timer) clearTimeout(timer);
            timer = setTimeout(tick, pollMs);
        }
    }

    tick();
})();
</script>
</body>
</html>
