<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Tunnel (cloudflared) connects from localhost and forwards the public https host/proto.
        $middleware->trustProxies(at: '127.0.0.1');

        $middleware->web(append: [
            \App\Http\Middleware\EnsureAccountActive::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A page this account may not open (often where the last person was before logging in): its own home, not a bare 403.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->isMethod('GET') && $request->user() && ! $request->expectsJson()) {
                return redirect($request->user()->home())->with('warning', 'That page is not open to this account.');
            }
        });
    })->create();
