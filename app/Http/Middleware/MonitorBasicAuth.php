<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MonitorBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = config('monitor.auth.user');
        $password = config('monitor.auth.password');

        if (empty($user) || $password === null || $password === '') {
            return $next($request);
        }

        if ($request->getUser() === $user && $request->getPassword() === $password) {
            return $next($request);
        }

        return response('Unauthorized', 401, [
            'WWW-Authenticate' => 'Basic realm="MySQL Monitor"',
        ]);
    }
}
