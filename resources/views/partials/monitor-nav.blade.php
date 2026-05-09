@php
    $authEnabled = $authEnabled ?? (filled(config('monitor.auth.user')) && config('monitor.auth.password') !== '');
@endphp
<nav class="navbar navbar-expand navbar-dark bg-dark border-bottom border-secondary mb-3">
    <div class="container-fluid px-3 flex-wrap gap-2">
        <span class="navbar-brand mb-0 h1 fs-5">{{ config('app.name') }}</span>
        <div class="navbar-nav flex-row gap-1 me-auto ms-md-3">
            <a class="nav-link px-2 py-1 rounded {{ ($active ?? 'dashboard') === 'dashboard' ? 'active' : '' }}" href="{{ url('/') }}">Dashboard</a>
            <a class="nav-link px-2 py-1 rounded {{ ($active ?? '') === 'queries' ? 'active' : '' }}" href="{{ route('queries') }}">Live queries</a>
            <a class="nav-link px-2 py-1 rounded {{ ($active ?? '') === 'slow' ? 'active' : '' }}" href="{{ route('slow-queries') }}">Slow queries</a>
            <a class="nav-link px-2 py-1 rounded {{ ($active ?? '') === 'drives' ? 'active' : '' }}" href="{{ route('drives') }}">Drive status</a>
        </div>
        <div class="d-flex align-items-center gap-3 ms-auto">
            @isset($pollLabel)
                <span class="text-secondary small d-none d-md-inline">{{ $pollLabel }}</span>
            @endisset
            <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50">
                <span class="pulse-dot me-1"></span> Live
            </span>
            @if($authEnabled)
                <span class="badge text-bg-secondary">Auth on</span>
            @endif
        </div>
    </div>
</nav>
