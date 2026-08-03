<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ResolveDistrictContext;
use App\Http\Middleware\SecurityHeaders;
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
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'active' => EnsureActiveUser::class,
            'role' => EnsureRole::class,
            'district' => ResolveDistrictContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, Request $request) {
            return response()->make(
                '<!DOCTYPE html><html><body><h2>500 Exception Diagnostic</h2>'.
                '<p><b>Exception:</b> '.htmlspecialchars($e->getMessage()).'</p>'.
                '<p><b>Class:</b> '.htmlspecialchars(get_class($e)).'</p>'.
                '<p><b>File:</b> '.htmlspecialchars($e->getFile()).':'.$e->getLine().'</p>'.
                '<pre>'.htmlspecialchars($e->getTraceAsString()).'</pre></body></html>',
                500
            );
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
