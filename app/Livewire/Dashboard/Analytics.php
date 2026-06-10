<?php

namespace App\Livewire\Dashboard;

use App\Enums\EmailJobStatus;
use App\Models\EmailJob;
use App\Models\Form;
use App\Models\FormSubmission;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Analytics extends Component
{
    public string $range = '30d';

    /**
     * Get the available date ranges.
     *
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

    /**
     * Total submissions within the current range.
     */
    #[Computed]
    public function totalSubmissions(): int
    {
        return FormSubmission::query()
            ->where('created_at', '>=', $this->since())
            ->count();
    }

    /**
     * Total submissions all time.
     */
    #[Computed]
    public function totalSubmissionsAllTime(): int
    {
        return FormSubmission::query()->count();
    }

    /**
     * Total forms.
     */
    #[Computed]
    public function totalForms(): int
    {
        return Form::query()->count();
    }

    /**
     * Active forms.
     */
    #[Computed]
    public function activeForms(): int
    {
        return Form::query()->where('is_archived', false)->count();
    }

    /**
     * Average submissions per day for the range.
     */
    #[Computed]
    public function averagePerDay(): float
    {
        $days = max(1, (int) $this->since()->diffInDays(now()) ?: 1);

        return round($this->totalSubmissions() / $days, 1);
    }

    /**
     * Submissions grouped by day for the chart.
     *
     * @return array<int, array{label: string, count: int, date: string}>
     */
    #[Computed]
    public function submissionsByDay(): array
    {
        $driver = DB::connection()->getDriverName();
        $format = $driver === 'sqlite' ? "strftime('%Y-%m-%d', created_at)" : 'DATE(created_at)';

        $rows = FormSubmission::query()
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
     * Submissions grouped by form.
     *
     * @return array<int, array{name: string, count: int, slug: string}>
     */
    #[Computed]
    public function submissionsByForm(): array
    {
        return Form::query()
            ->withCount(['submissions' => function ($query): void {
                $query->where('created_at', '>=', $this->since());
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
     * Email job counts grouped by status within the range.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function emailStatusBreakdown(): array
    {
        $rows = EmailJob::query()
            ->where('created_at', '>=', $this->since())
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        return [
            EmailJobStatus::Sent->value => (int) ($rows[EmailJobStatus::Sent->value] ?? 0),
            EmailJobStatus::Failed->value => (int) ($rows[EmailJobStatus::Failed->value] ?? 0),
            EmailJobStatus::Pending->value => (int) ($rows[EmailJobStatus::Pending->value] ?? 0),
            EmailJobStatus::Processing->value => (int) ($rows[EmailJobStatus::Processing->value] ?? 0),
        ];
    }

    /**
     * Email success rate over the current range (0-100).
     */
    #[Computed]
    public function emailSuccessRate(): float
    {
        $status = $this->emailStatusBreakdown();
        $total = array_sum($status);
        if ($total === 0) {
            return 0.0;
        }

        return round(((int) $status[EmailJobStatus::Sent->value] / $total) * 100, 1);
    }

    /**
     * Email failure rate over the current range (0-100).
     */
    #[Computed]
    public function emailFailureRate(): float
    {
        $status = $this->emailStatusBreakdown();
        $total = array_sum($status);
        if ($total === 0) {
            return 0.0;
        }

        return round(((int) $status[EmailJobStatus::Failed->value] / $total) * 100, 1);
    }

    public function render(): mixed
    {
        return view('livewire.dashboard.analytics');
    }
}
