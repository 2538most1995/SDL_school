<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->nonce($request);

        return $this->apply($request, $next($request));
    }

    public function apply(Request $request, Response $response): Response
    {
        $nonce = $this->nonce($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $privatePdf = $request->is('api/v1/learning/lesson-plans/*/file') && $response->isSuccessful()
            && $response->headers->get('Content-Type') === 'application/pdf';
        $response->headers->set('X-Frame-Options', $privatePdf ? 'SAMEORIGIN' : 'DENY');
        $ancestors = $privatePdf ? "'self'" : "'none'";
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors {$ancestors}; frame-src 'self' blob: https://www.youtube-nocookie.com https://drive.google.com https://docs.google.com https://onedrive.live.com https://*.sharepoint.com; object-src 'self' blob:; worker-src 'self' blob:; script-src 'self' 'nonce-{$nonce}' 'wasm-unsafe-eval'; script-src-attr 'none'; style-src 'self' 'nonce-{$nonce}'; style-src-attr 'unsafe-inline'; img-src 'self' data: blob: https://drive.google.com https://lh3.googleusercontent.com; font-src 'self' data:; connect-src 'self'",
        );

        if (str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function nonce(Request $request): string
    {
        $nonce = $request->attributes->get('csp_nonce');
        if (! is_string($nonce) || $nonce === '') {
            $nonce = base64_encode(random_bytes(32));
            $request->attributes->set('csp_nonce', $nonce);
        }
        Vite::useCspNonce($nonce);

        return $nonce;
    }
}
