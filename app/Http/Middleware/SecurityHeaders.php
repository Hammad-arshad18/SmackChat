<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser hardening. The CSP is only sent in production so the Vite
 * dev server (and its inline HMR client) keeps working locally.
 *
 * If you add a third-party script, font, or API host, allow it here or the
 * browser will block it. `connect-src` deliberately allows any https/wss host
 * because Ably regions and Hugging Face model hosts change over time.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(24);

        $request->attributes->set('csp_nonce', $nonce);

        if (app()->isProduction()) {
            View::share('cspNonce', $nonce);
        }

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(self), microphone=(self), display-capture=(self), geolocation=(self), payment=()',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        if (app()->isProduction()) {
            $headers['Content-Security-Policy'] = $this->policy($nonce);
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self' 'wasm-unsafe-eval' 'nonce-".$nonce."' blob:",
            "worker-src 'self' blob:",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' data: https://fonts.bunny.net",
            "img-src 'self' data: blob:",
            "media-src 'self' blob:",
            "connect-src 'self' https: wss:",
            "frame-src 'self' blob:",
            "manifest-src 'self'",
        ]);
    }
}
