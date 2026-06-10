<?php

use App\Http\Middleware\VerifyFormApiKey;
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
        $middleware->alias([
            'form.key' => VerifyFormApiKey::class,
        ]);

        // Trust proxy headers when behind a load balancer (Laravel Cloud,
        // nginx, etc.). Set TRUSTED_PROXIES in production (e.g. "*" or a
        // comma-separated list of IPs/CIDRs) so the request IP, scheme,
        // and host are read from X-Forwarded-* headers.
        //
        // Note: this runs at bootstrap time before the config repository is
        // bound, so we must read the env value directly. The same value is
        // also available as config('forms.trusted_proxies') at runtime.
        $trustedProxies = env('TRUSTED_PROXIES');
        if (! empty($trustedProxies)) {
            $middleware->trustProxies(
                at: $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO
                    | Request::HEADER_X_FORWARDED_PREFIX
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
