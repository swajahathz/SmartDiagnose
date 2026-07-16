<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} — Sign in</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    @include('partials.monitor-styles')
</head>
<body class="d-flex align-items-center min-vh-100 p-3" style="background: #0f1419;">
<div class="w-100" style="max-width: 400px; margin: 0 auto;">
    <div class="card border-secondary shadow-lg">
        <div class="card-body p-4">
            <h1 class="h4 mb-1 text-center" style="color: #f0f6fc;">{{ config('app.name') }}</h1>
            <p class="text-secondary small text-center mb-4">Sign in to open dashboards and APIs</p>

            @if ($errors->any())
                <div class="alert alert-danger py-2 small mb-3" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="post" action="{{ route('login') }}" class="vstack gap-3">
                @csrf
                <div>
                    <label for="email" class="form-label small text-secondary mb-1">Email</label>
                    <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                           class="form-control bg-dark text-light border-secondary" placeholder="you@example.com">
                </div>
                <div>
                    <label for="password" class="form-label small text-secondary mb-1">Password</label>
                    <input type="password" name="password" id="password" required autocomplete="current-password"
                           class="form-control bg-dark text-light border-secondary" placeholder="••••••••">
                </div>
                <div class="form-check">
                    <input type="checkbox" name="remember" value="1" id="remember" class="form-check-input"
                           {{ old('remember') ? 'checked' : '' }}>
                    <label class="form-check-label small text-secondary" for="remember">Remember me</label>
                </div>
                <button type="submit" class="btn btn-primary w-100">Sign in</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
