<?php

use App\Http\Middleware\EnsureMfa;
use App\Http\Middleware\RequireRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => RequireRole::class,
            'mfa' => EnsureMfa::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        // Mini App sign-in is proven by Telegram's signature, not by a session cookie.
        $middleware->validateCsrfTokens(except: ['tg/auth']);
        $middleware->web(prepend: [\App\Http\Middleware\EmbeddableSessionCookie::class]);
        // An HTTPS tunnel or proxy on this machine (e.g. cloudflared) forwards the real
        // scheme and host; only these addresses may set X-Forwarded-* headers.
        $middleware->trustProxies(at: array_values(array_filter(array_map('trim',
            explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1'))))));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
