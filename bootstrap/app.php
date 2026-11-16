<?php

use App\Http\Middleware\EnsureWorkspaceMember;
use App\Http\Middleware\SecurityHeaders;
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
        $middleware->statefulApi();
        // Empty on purpose: with no trusted proxy, X-Forwarded-* headers are
        // ignored. Office-network presence and IP rate limits read the real
        // REMOTE_ADDR, so a client cannot spoof them with a header.
        // If you put a load balancer or CDN in front of the app, list its
        // addresses here (never use '*': every security decision would trust
        // whatever the caller sends).
        $middleware->trustProxies(at: []);
        $middleware->append(SecurityHeaders::class);
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
