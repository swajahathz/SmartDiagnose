<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => [__('These credentials do not match our records.')],
            ]);
        }

        $request->session()->regenerate();

        // Guests hitting /api/* store url.intended as JSON endpoints — send them to the dashboard instead.
        return $this->redirectAfterLogin($request);
    }

    private function redirectAfterLogin(Request $request): RedirectResponse
    {
        $fallback = '/';
        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended) || $intended === '') {
            return redirect($fallback);
        }

        $path = parse_url($intended, PHP_URL_PATH);

        if (! is_string($path) || $path === '' || $path === '/') {
            return redirect($fallback);
        }

        if (str_starts_with($path, '/api') || $path === '/login') {
            return redirect($fallback);
        }

        return redirect()->to($intended);
    }
}
