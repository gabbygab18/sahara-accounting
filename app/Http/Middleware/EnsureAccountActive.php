<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Someone deactivated while logged in is signed out on their next click. */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->disabled_at) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login')->withErrors(['email' => 'This account has been deactivated.']);
        }

        return $next($request);
    }
}
