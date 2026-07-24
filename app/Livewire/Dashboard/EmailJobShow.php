<?php

namespace App\Livewire\Dashboard;

use App\Enums\EmailJobStatus;
use App\Jobs\ProcessFormSubmissionEmail;
use App\Models\EmailJob;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Email job')]
#[Layout('layouts.app')]
class EmailJobShow extends Component
{
    public EmailJob $job;

    public function mount(EmailJob $job): void
    {
        // SaaS isolation: only the form's owner may view an email job.
        $this->authorize('view', $job);

        $this->job = $job;
    }

    public function retry(): void
    {
        $this->authorize('view', $this->job);

        if ($this->job->status !== EmailJobStatus::Failed->value) {
            Flux::toast(variant: 'warning', text: __('Only failed jobs can be retried.'));

            return;
        }

        $this->job->resetForRetry();
        ProcessFormSubmissionEmail::dispatch($this->job);
        $this->job->refresh();

        Flux::toast(variant: 'success', text: __('Job re-queued.'));
    }

    /**
     * @return array<int, array{label: string, value: string|null}>
     */
    #[Computed]
    public function timeline(): array
    {
        $items = [
            ['label' => __('Created'), 'value' => $this->job->created_at?->toDayDateTimeString()],
            ['label' => __('Queued'), 'value' => $this->job->queued_at?->toDayDateTimeString()],
            ['label' => __('Started'), 'value' => $this->job->started_at?->toDayDateTimeString()],
            ['label' => __('Completed'), 'value' => $this->job->completed_at?->toDayDateTimeString()],
        ];

        return $items;
    }

    public function render(): View
    {
        return view('livewire.dashboard.email-job-show');
    }
}
