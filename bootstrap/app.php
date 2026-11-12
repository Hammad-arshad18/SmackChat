<?php

use App\Http\Middleware\CrossOriginIsolation;
use App\Http\Middleware\EnsureWorkspaceMember;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cross-origin isolation for the app shell: enables SharedArrayBuffer so the
        // on-device AI worker can use multi-threaded SIMD WASM without WebGPU.
        $middleware->append(CrossOriginIsolation::class);
        $middleware->statefulApi();
        // Hostinger (and most reverse proxies) forward the real client IP.
        // Office presence checks depend on Request::ip() seeing that address.
        $middleware->trustProxies(at: '*');
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return '/login';
        });
        $middleware->alias([
            'workspace' => EnsureWorkspaceMember::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage() ?: 'Unauthenticated.'], 401);
            }

            return null;
        });
    })->create();
