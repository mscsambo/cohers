<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telegram Web and Desktop show the Mini App inside a frame, where browsers drop
 * ordinary (SameSite=Lax) cookies, so the person is signed out again at once.
 * Over HTTPS (the Mini App tunnel) the session cookie is therefore sent as
 * SameSite=None; Secure; Partitioned. Plain-http dashboard access is unchanged.
 */
class EmbeddableSessionCookie
{
    /** The configured cookie settings, kept so plain-http requests always get them back. */
    private static ?array $defaults = null;

    public function handle(Request $request, Closure $next): Response
    {
        self::$defaults ??= [
            'session.secure' => config('session.secure'),
            'session.same_site' => config('session.same_site'),
            'session.partitioned' => config('session.partitioned'),
        ];

        config($request->isSecure()
            ? ['session.secure' => true, 'session.same_site' => 'none', 'session.partitioned' => true]
            : self::$defaults);

        return $next($request);
    }
}
