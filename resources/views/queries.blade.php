<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} — Live queries</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    @include('partials.monitor-styles')
</head>
<body>
@include('partials.monitor-nav', ['active' => 'queries', 'pollLabel' => $pollLabel, 'authEnabled' => $authEnabled])

<div class="container-fluid px-3 pb-5">
    <div id="banner-error" class="alert alert-danger d-none" role="alert"></div>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <p class="text-secondary small mb-0">
            Data from <code>SHOW FULL PROCESSLIST</code>. Sorted by <strong>Time</strong> (longest first). Highlight = &ge; {{ config('monitor.long_query_seconds', 3) }}s.
        </p>
        <span class="badge bg-secondary" id="meta-line">—</span>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span>Active threads</span>
            <small class="text-secondary">Last: <span id="last-ts">—</span></small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped mb-0">
                    <thead>
                    <tr>
                        <th>Id</th>
                        <th>User</th>
                        <th>Host</th>
                        <th>DB</th>
                        <th>Command</th>
                        <th class="text-end">Time (s)</th>
                        <th>State</th>
                        <th>Query / Info</th>
                    </tr>
                    </thead>
                    <tbody id="tbl-body">
                    <tr><td colspan="8" class="text-secondary px-3 py-4">Loading…</td></tr>
                    </tbody>
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
    const tbl = document.getElementById('tbl-body');
    const banner = document.getElementById('banner-error');
    const meta = document.getElementById('meta-line');
    let prevIds = new Set();

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function sortRows(rows) {
        return [...rows].sort((a, b) => (Number(b.Time) || 0) - (Number(a.Time) || 0));
    }

    async function tick() {
        try {
            const res = await fetch('{{ url('/api/processlist') }}', { credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            document.getElementById('last-ts').textContent = data.ts || '—';
            if (!data.ok) {
                banner.textContent = data.error || 'MySQL error';
                banner.classList.remove('d-none');
                tbl.innerHTML = '<tr><td colspan="8" class="text-danger px-3 py-4">'+ escapeHtml(String(data.error || 'Error')) +'</td></tr>';
                return;
            }
            banner.classList.add('d-none');
            const rows = sortRows(data.rows || []);
            const nextIds = new Set(rows.map(r => String(r.Id)));
            meta.textContent = rows.length + ' row(s) · poll ' + (pollMs / 1000) + 's';

            if (!rows.length) {
                tbl.innerHTML = '<tr><td colspan="8" class="text-secondary px-3 py-4">No active threads</td></tr>';
                prevIds = nextIds;
                return;
            }

            tbl.innerHTML = rows.map(r => {
                const isNew = !prevIds.has(String(r.Id));
                const cls = (r.long_running ? 'long-query ' : '') + (isNew ? 'fresh-row' : '');
                const info = (r.Info !== null && r.Info !== undefined) ? escapeHtml(String(r.Info)) : '<span class="text-secondary">—</span>';
                return '<tr class="'+ cls.trim() +'">' +
                    ['Id','User','Host','db','Command'].map(k => '<td class="small">'+ escapeHtml(String(r[k] ?? '')) +'</td>').join('') +
                    '<td class="small text-end">'+ escapeHtml(String(r.Time ?? '')) +'</td>' +
                    '<td class="small">'+ escapeHtml(String(r.State ?? '')) +'</td>' +
                    '<td class="query-cell">'+ info +'</td>' +
                    '</tr>';
            }).join('');
            prevIds = nextIds;
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
