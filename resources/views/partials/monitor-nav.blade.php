<nav class="navbar navbar-expand navbar-dark bg-dark border-bottom border-secondary mb-3">
    <div class="container-fluid px-3 flex-wrap gap-2">
        <span class="navbar-brand mb-0 h1 fs-5">{{ config('app.name') }}</span>
        <div class="navbar-nav flex-row gap-1 me-auto ms-md-3">
            <a class="nav-link px-2 py-1 rounded {{ ($active ?? 'dashboard') === 'dashboard' ? 'active' : '' }}" href="{{ url('/') }}">Dashboard</a>
            <a class="nav-link px-2 py-1 rounded {{ ($active ?? '') === 'queries' ? 'active' : '' }}" href="{{ route('queries') }}">Live queries</a>
            <a class="nav-link px-2 py-1 rounded {{ ($active ?? '') === 'slow' ? 'active' : '' }}" href="{{ route('slow-queries') }}">Slow queries</a>
            <a class="nav-link px-2 py-1 rounded {{ ($active ?? '') === 'drives' ? 'active' : '' }}" href="{{ route('drives') }}">Drive status</a>
            <a class="nav-link px-2 py-1 rounded {{ ($active ?? '') === 'freeradius' ? 'active' : '' }}" href="{{ route('freeradius-log') }}">FreeRADIUS log</a>
        </div>
        <div class="d-flex align-items-center gap-2 ms-auto flex-wrap">
            @isset($pollLabel)
                <span class="text-secondary small d-none d-md-inline">{{ $pollLabel }}</span>
            @endisset
            @auth
                <span class="text-secondary small d-none d-sm-inline text-truncate" style="max-width: 12rem;">{{ Auth::user()->name }}</span>
                <form action="{{ route('logout') }}" method="POST" class="d-inline m-0">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Log out</button>
                </form>
            @endauth
            <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50">
                <span class="pulse-dot me-1"></span> Live
            </span>
        </div>
    </div>
</nav>
