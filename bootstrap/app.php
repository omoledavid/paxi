<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Register NelloBytes API routes under /api/v1/nellobytes
            Route::middleware('api')
                ->prefix('api/v1/nellobytes')
                ->group(base_path('routes/api_v1_nellobytes.php'));

            // Register webhook routes without web/CSRF middleware
            Route::prefix('webhooks')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \App\Http\Middleware\EnsureRecentActivity::class,
            // \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'check.status' => \App\Http\Middleware\CheckStatus::class,
            'token.recent' => \App\Http\Middleware\EnsureRecentActivity::class,
            'throttle.verification' => \App\Http\Middleware\ThrottleEmailVerification::class,
        ]);

        // Exclude webhook routes from CSRF protection
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
