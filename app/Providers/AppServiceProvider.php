<?php

namespace App\Providers;

use App\Http\Middleware\RecordImpersonator;
use App\Models\AuditLog;
use App\Models\EmailJob;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Policies\EmailJobPolicy;
use App\Policies\FormPolicy;
use App\Policies\FormSubmissionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies as TrustProxiesMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        $this->registerPolicies();
        $this->registerImpersonationAuditHook();
    }

    /**
     * Map Eloquent models to their policies.
     *
     * Form, FormSubmission, and EmailJob are all user-scoped SaaS
     * resources; each is owned by exactly one user, and only the
     * owner may view/edit/delete them. The policies live in
     * `App\Policies\` and are resolved by the `Gate` facade so
     * `$user->can('update', $form)` and `Gate::authorize('view',
     * $submission)` both work.
     */
    protected function registerPolicies(): void
    {
        Gate::policy(Form::class, FormPolicy::class);
        Gate::policy(FormSubmission::class, FormSubmissionPolicy::class);
        Gate::policy(EmailJob::class, EmailJobPolicy::class);
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
     * Wire up the impersonation audit-log hook.
     *
     * When an admin is logged in as another user, the session carries
     * an `impersonator_id`. We use Eloquent's `creating` event on the
     * AuditLog model to stamp that id into the new row's `metadata`
     * so the audit trail always shows who *really* performed the
     * action.
     */
    protected function registerImpersonationAuditHook(): void
    {
        if (! AuditLog::class) {
            return;
        }

        AuditLog::creating(function (AuditLog $log): void {
            RecordImpersonator::stampAuditLog($log);
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
