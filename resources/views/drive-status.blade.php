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
    'pollLabel' => 'Poll: '.$pollSeconds.'s (iostat ~'.(int) config('monitor.iostat_interval', 1).'s/sample)',
    'authEnabled' => $authEnabled,
])

<div class="container-fluid px-3 pb-5">
    <div id="banner-error" class="alert alert-danger d-none" role="alert"></div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6 col-xl-4">
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
                    <div class="metric-label">Mean %util*</div>
                    <div class="metric-value fs-4" id="m-d-mean">—</div>
                    <small class="text-secondary">Tracked devices</small>
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
        Data from <code>iostat -y -x {{ (int) config('monitor.iostat_interval', 1) }} 1</code> (sysstat). Loop/sr devices are hidden from peak rollup.
        Last update: <span id="last-ts">—</span>
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
(function () {
    const pollMs = {{ (float) $pollSeconds }} * 1000;
    const apiUrl = @json(route('api.drive-status'));
    const historyLen = 36;
    const labels = Array.from({length: historyLen}, () => '');

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
                y: { beginAtZero: true, max: 100, grid: { color: '#30363d' }, ticks: { color: '#8b949e' } },
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
        return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
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

    async function tick() {
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

            const meanU = s.mean_util_percent;
            document.getElementById('m-d-mean').textContent = meanU != null ? Number(meanU).toFixed(1) : '—';

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

            renderDevices(d.devices || []);
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
