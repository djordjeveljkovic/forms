<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Global admin overview.
 *
 * Shows KPIs across every user — distinct from the per-user
 * Analytics dashboard which scopes to the own user.
 */
#[Title('Admin dashboard')]
#[Layout('layouts.admin')]
class AdminDashboard extends Component
{
    public string $range = '30d';

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function ranges(): array
    {
        return [
            '24h' => 'Last 24 hours',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            '90d' => 'Last 90 days',
        ];
    }

    /**
     * Update the active range.
     */
    public function setRange(string $range): void
    {
        if (! array_key_exists($range, $this->ranges())) {
            return;
        }

        $this->range = $range;
    }

    /**
     * Resolve the start of the range window.
     */
    #[Computed]
    public function since(): Carbon
    {
        $now = Carbon::now();

        return match ($this->range) {
            '24h' => $now->copy()->subDay(),
            '7d' => $now->copy()->subDays(7),
            '90d' => $now->copy()->subDays(90),
            default => $now->copy()->subDays(30),
        };
    }

    #[Computed]
    public function totalUsers(): int
    {
        return User::query()->count();
    }

    #[Computed]
    public function newUsersInRange(): int
    {
        return User::query()->where('created_at', '>=', $this->since())->count();
    }

    #[Computed]
    public function adminUserCount(): int
    {
        return User::query()->role(User::ROLE_ADMIN)->count();
    }

    #[Computed]
    public function totalForms(): int
    {
        return Form::query()->count();
    }

    #[Computed]
    public function activeForms(): int
    {
        return Form::query()->where('is_archived', false)->count();
    }

    #[Computed]
    public function totalSubmissions(): int
    {
        return FormSubmission::query()->where('created_at', '>=', $this->since())->count();
    }

    #[Computed]
    public function totalSubmissionsAllTime(): int
    {
        return FormSubmission::query()->count();
    }

    #[Computed]
    public function activeSubscriptions(): int
    {
        return Subscription::query()->active()->count();
    }

    /**
     * Monthly recurring revenue in cents — sum of active subscription
     * plan prices.
     */
    #[Computed]
    public function mrrCents(): int
    {
        return (int) DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereIn('subscriptions.status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
            ->where(function ($q): void {
                $q->whereNull('subscriptions.ends_at')->orWhere('subscriptions.ends_at', '>', now());
            })
            ->sum('plans.price_cents');
    }

    #[Computed]
    public function mrrAmount(): string
    {
        return number_format($this->mrrCents() / 100, 2);
    }

    /**
     * @return array<int, array{label: string, count: int, date: string}>
     */
    #[Computed]
    public function signupsByDay(): array
    {
        $driver = DB::connection()->getDriverName();
        $format = $driver === 'sqlite' ? "strftime('%Y-%m-%d', created_at)" : 'DATE(created_at)';

        $rows = User::query()
            ->selectRaw("{$format} as date, COUNT(*) as count")
            ->where('created_at', '>=', $this->since())
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date');

        $period = CarbonPeriod::create($this->since()->startOfDay(), now()->endOfDay());
        $series = [];

        foreach ($period as $day) {
            $key = $day->format('Y-m-d');
            $series[] = [
                'label' => $day->format('M j'),
                'date' => $key,
                'count' => (int) ($rows[$key] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * @return array<int, array{name: string, slug: string, count: int}>
     */
    #[Computed]
    public function topForms(): array
    {
        return Form::query()
            ->withCount(['submissions' => function ($q): void {
                $q->where('created_at', '>=', $this->since());
            }])
            ->orderByDesc('submissions_count')
            ->limit(8)
            ->get()
            ->map(fn (Form $form) => [
                'name' => $form->name,
                'slug' => $form->slug,
                'count' => (int) $form->submissions_count,
            ])
            ->all();
    }

    /**
     * @return array<int, array{plan: string, slug: string, count: int, price_cents: int}>
     */
    #[Computed]
    public function subscriptionsByPlan(): array
    {
        return Plan::query()
            ->withCount(['activeSubscriptions'])
            ->get()
            ->map(fn (Plan $plan) => [
                'plan' => $plan->name,
                'slug' => $plan->slug,
                'count' => (int) $plan->active_subscriptions_count,
                'price_cents' => (int) $plan->price_cents,
            ])
            ->all();
    }

    /**
     * Recent admin actions.
     *
     * @return Collection<int, AuditLog>
     */
    #[Computed]
    public function recentAdminActions()
    {
        return AuditLog::query()
            ->whereIn('action', [
                'admin.impersonation.started',
                'admin.impersonation.stopped',
                'user.created',
                'user.updated',
                'user.deleted',
                'user.role.changed',
                'user.plan.changed',
                'user.2fa.disabled',
                'plan.created',
                'plan.updated',
                'plan.deleted',
            ])
            ->with('user')
            ->latest('created_at')
            ->limit(10)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.admin.admin-dashboard');
    }
}
