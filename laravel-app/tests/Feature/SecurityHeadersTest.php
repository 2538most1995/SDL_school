<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function test_html_uses_unique_matching_nonces_and_is_not_cacheable(): void
    {
        $first = $this->get('/app')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        preg_match('/<meta name="csp-nonce" content="([^"]+)"/', $first->getContent(), $match);
        $this->assertNotEmpty($match[1] ?? null);
        $nonce = $match[1];
        $this->assertStringContainsString('property="csp-nonce" nonce="'.$nonce.'"', $first->getContent());
        $policy = $first->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' 'nonce-{$nonce}' 'wasm-unsafe-eval'", $policy);
        $this->assertStringContainsString("style-src 'self' 'nonce-{$nonce}'", $policy);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
        $this->assertStringNotContainsString("style-src 'self' 'unsafe-inline'", $policy);
        $this->assertStringContainsString("style-src-attr 'unsafe-inline'", $policy);
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        preg_match_all('/<(?:script|link)\b[^>]*nonce="([^"]+)"/', $first->getContent(), $tags);
        $this->assertNotEmpty($tags[1]);
        $this->assertSame([$nonce], array_values(array_unique($tags[1])));

        $second = $this->get('/login')->assertOk();
        preg_match('/<meta name="csp-nonce" content="([^"]+)"/', $second->getContent(), $next);
        $this->assertNotSame($nonce, $next[1]);
    }

    public function test_session_cookie_stays_http_only_while_csrf_cookie_stays_readable(): void
    {
        $response = $this->get('/app')->assertOk();
        $cookies = collect($response->headers->getCookies())->keyBy(fn ($cookie) => $cookie->getName());
        $this->assertTrue($cookies[config('session.cookie')]->isHttpOnly());
        $this->assertFalse($cookies['XSRF-TOKEN']->isHttpOnly());
    }

    public function test_early_errors_and_https_responses_keep_security_headers_and_json_contracts(): void
    {
        Route::get('/api/security-regression/validation', fn () => abort(422, 'Invalid test input'));
        Route::get('/api/security-regression/failure', fn () => throw new \RuntimeException('private-server-detail'));
        Route::get('/up/security-regression-failure', fn () => throw new \RuntimeException('private-server-detail'));
        foreach ([401, 403, 419, 429] as $status) {
            Route::get('/api/security-regression/'.$status, fn () => abort($status));
            $this->getJson('/api/security-regression/'.$status)->assertStatus($status)->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        $this->getJson('/api/security-regression/validation')->assertStatus(422)->assertJsonPath('message', 'Invalid test input');
        $this->getJson('/api/security-regression/failure')->assertStatus(500)->assertDontSee('private-server-detail')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/security-regression/not-found')->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
        $errorPage = $this->get('/up/security-regression-failure')->assertStatus(500)->assertDontSee('private-server-detail');
        preg_match("/style-src 'self' 'nonce-([^']+)'/", $errorPage->headers->get('Content-Security-Policy'), $errorNonce);
        $this->assertNotEmpty($errorNonce[1] ?? null);
        $errorPage->assertSee('<style nonce="'.$errorNonce[1].'">', false);
        $this->get('https://localhost/app')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->get('http://localhost/app')->assertOk()->assertHeaderMissing('Strict-Transport-Security');

        // Post-size rejection runs before routing and must still carry headers.
        $this->withServerVariables(['CONTENT_LENGTH' => PHP_INT_MAX])->postJson('/auth/login', [])
            ->assertStatus(413)->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
