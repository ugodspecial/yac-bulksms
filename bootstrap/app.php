<?php

use App\Http\Middleware\EnsureApplicationIsInstalled;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'password.changed' => EnsurePasswordIsChanged::class,
        ]);

        // Every browser request is sent to the installer until it has run.
        $middleware->web(append: [
            EnsureApplicationIsInstalled::class,
        ]);

        // The Termii delivery-report webhook is called by the provider, so it
        // carries no session. Signature verification happens in the controller.
        $middleware->validateCsrfTokens(except: [
            'webhooks/termii*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('webhooks/*') || $request->expectsJson()
        );
    })->create();
