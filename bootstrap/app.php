<?php

use App\Http\Middleware\VerifyInternalApiKey;
use App\Http\Middleware\VerifyTwilioSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->booting(function (Application $app): void {
        if (file_exists(base_path('.env.local'))) {
            $app->beforeBootstrapping(LoadEnvironmentVariables::class, function (Application $application): void {
                $application->loadEnvironmentFrom('.env.local');
            });
        }
    })
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: ['*'],   // replace with the tunnel/proxy IPs; use '*' only if you must trust all
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );

        $middleware->use([
            HandleCors::class,
        ]);

        $middleware->alias([
            'internal.api' => VerifyInternalApiKey::class,
            'twilio.signature' => VerifyTwilioSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
