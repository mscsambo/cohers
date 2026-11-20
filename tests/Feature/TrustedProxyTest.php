<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Behind an HTTPS tunnel on the same machine, links must use the public https address. */
class TrustedProxyTest extends TestCase
{
    private array $forwarded = [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'demo-tunnel.trycloudflare.com',
        'X-Forwarded-Port' => '443',
    ];

    public function test_local_tunnel_gives_https_links(): void
    {
        $this->withHeaders($this->forwarded)->get('/login')
            ->assertOk()
            ->assertSee('action="https://demo-tunnel.trycloudflare.com/login"', false);
    }

    /** Telegram Web/Desktop frame the Mini App: over https the session cookie must survive that. */
    public function test_https_session_cookie_works_inside_telegram_frames(): void
    {
        $cookie = fn ($res) => strtolower(collect($res->headers->all('set-cookie'))->first(fn ($c) => str_contains($c, config('session.cookie'))) ?? '');

        $https = $cookie($this->withHeaders($this->forwarded)->get('/tg'));
        $this->assertStringContainsString('samesite=none', $https);
        $this->assertStringContainsString('secure', $https);
        $this->assertStringContainsString('partitioned', $https);

        $http = $cookie($this->flushHeaders()->get('http://localhost/tg'));
        $this->assertStringContainsString('samesite=lax', $http);
        $this->assertStringNotContainsString('secure', $http);
    }

    public function test_remote_clients_cannot_fake_the_address(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders($this->forwarded)->get('/login')
            ->assertOk()
            ->assertDontSee('https://demo-tunnel.trycloudflare.com', false);
    }
}
