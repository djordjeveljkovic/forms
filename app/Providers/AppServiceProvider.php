<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies as TrustProxiesMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiters();
        $this->configureTrustedProxies();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Configure the rate limiters used by the application.
     */
    protected function configureRateLimiters(): void
    {
        RateLimiter::for('forms', function (Request $request): Limit {
            $perHour = (int) config('forms.submission_rate_limit', 60);
            $form = $request->route('form');
            $formKey = is_object($form) && method_exists($form, 'getKey') ? (string) $form->getKey() : (string) $form;

            $key = $formKey !== '' ? sha1($formKey.'|'.$request->ip()) : $request->ip();

            return Limit::perHour($perHour)->by($key);
        });
    }

    /**
     * Configure trusted proxies when the app runs behind a load balancer.
     *
     * Reads the comma-separated list (or "*") from config('forms.trusted_proxies')
     * and tells the framework to honour X-Forwarded-* headers.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = config('forms.trusted_proxies');
        if (empty($proxies)) {
            return;
        }

        $proxies = $proxies === '*'
            ? '*'
            : collect(explode(',', (string) $proxies))
                ->map(fn (string $entry) => trim($entry))
                ->filter()
                ->all();

        if (empty($proxies)) {
            return;
        }

        TrustProxiesMiddleware::at($proxies);
        TrustProxiesMiddleware::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PREFIX
        );
    }
}
