<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} — FreeRADIUS log</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    @include('partials.monitor-styles')
</head>
<body>
@include('partials.monitor-nav', [
    'active' => 'freeradius',
    'pollLabel' => 'Poll: '.$pollSeconds.'s',
])

<div class="container-fluid px-3 pb-5">
    <div id="banner-error" class="alert alert-danger d-none" role="alert"></div>

    <div class="alert alert-secondary border-secondary mb-2 py-2 small">
        <strong>This app does not start or stop FreeRADIUS.</strong> It only <strong>reads</strong> the file path in
        <code>FREERADIUS_LOG_PATH</code>. You do <strong>not</strong> need to stop the service for SmartDiagnose to work.
    </div>
    <div class="alert alert-secondary border-secondary mb-3 py-2 small mb-0">
        <strong>Why people stop the service:</strong> <code>freeradius -X</code> is foreground debug. If the normal daemon is already
        running (systemd), ports may be in use — many admins run <code>systemctl stop freeradius</code> first, then <code>freeradius -X</code>.
        That is only for manual debugging on the server, not required here.
        <strong>For 24/7 logs</strong>, keep the service running and point <code>FREERADIUS_LOG_PATH</code> at the file your
        <em>running</em> server writes (via <code>log { }</code> in config), not only a one-off <code>-X</code> redirect.
    </div>
    <div class="alert alert-secondary border-secondary mb-3 py-2 small mb-0">
        <strong>Setup:</strong> <code>freeradius -X</code> prints to the terminal unless redirected. Example:
        <code>freeradius -X &gt;&gt; /var/log/freeradius/debug.log 2&gt;&amp;1</code> — or configure
        <code>log { ... }</code> under <code>/etc/freeradius/</code>. Ensure the web user can read that file.
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>FreeRADIUS log tail</span>
            <small class="text-secondary text-truncate" style="max-width: 70%" id="log-path"></small>
        </div>
        <div class="card-body">
            <pre class="log-box mb-0" id="log-lines"></pre>
            <small class="text-secondary mt-1 d-block" id="log-meta"></small>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
<script>
(function () {
    const pollMs = {{ (float) $pollSeconds }} * 1000;
    const apiUrl = @json(route('api.freeradius-log'));

    async function tick() {
        const banner = document.getElementById('banner-error');
        const pathEl = document.getElementById('log-path');
        const linesEl = document.getElementById('log-lines');
        const metaEl = document.getElementById('log-meta');
        try {
            const res = await fetch(apiUrl, { credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const d = await res.json();
            banner.classList.add('d-none');
            pathEl.textContent = d.path ? d.path : '';

            if (!d.enabled) {
                linesEl.textContent = '(FreeRADIUS log view disabled in config)';
                metaEl.textContent = d.ts || '';
                return;
            }

            if (d.error) {
                linesEl.textContent = '';
                metaEl.textContent = d.error;
                return;
            }

            linesEl.textContent = (d.lines && d.lines.length)
                ? d.lines.join('\n')
                : '(empty)';
            metaEl.textContent = (d.lines ? d.lines.length : 0) + ' lines · ' + (d.ts || '');
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
