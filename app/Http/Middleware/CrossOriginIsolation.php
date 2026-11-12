<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the app shell cross-origin isolated so WebAssembly may use SharedArrayBuffer.
 * That is what lets ONNX Runtime run multi-threaded SIMD inference when WebGPU is
 * unavailable or blocked (for example Android Chrome with a blocklisted adapter).
 *
 * Scope: HTML documents only. API JSON, assets, uploads and downloads are untouched.
 *
 * `credentialless` is used instead of `require-corp` because require-corp would block
 * cross-origin assets that do not send a CORP header (the Bunny font stylesheet, Vite's
 * dev CSS). Safari does not support `credentialless`; it ignores the header and simply
 * stays unisolated, which is safe because Safari has no WebGPU in workers anyway.
 */
class CrossOriginIsolation
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Embedder-Policy', 'credentialless');

        return $response;
    }
}
