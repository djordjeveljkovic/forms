<?php

namespace App\Livewire\Dashboard;

use App\Enums\EmailJobStatus;
use App\Jobs\ProcessFormSubmissionEmail;
use App\Models\EmailJob;
use App\Models\Form;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Email jobs')]
#[Layout('layouts.app')]
class EmailJobs extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    #[Url(as: 'form', except: '')]
    public string $formFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedFormFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'formFilter']);
        $this->resetPage();
    }

    /**
     * Retry a failed job.
     */
    public function retry(int $jobId): void
    {
        $job = EmailJob::query()->findOrFail($jobId);

        if ($job->status !== EmailJobStatus::Failed->value) {
            Flux::toast(variant: 'warning', text: __('Only failed jobs can be retried.'));

            return;
        }

        $job->resetForRetry();
        ProcessFormSubmissionEmail::dispatch($job);

        Flux::toast(variant: 'success', text: __('Job re-queued.'));
    }

    /**
     * Retry all failed jobs in the current filter.
     */
    public function retryAllFailed(): void
    {
        $count = 0;
        /** @var Collection<int, EmailJob> $failed */
        $failed = $this->buildQuery()
            ->where('status', EmailJobStatus::Failed->value)
            ->get();

        foreach ($failed as $job) {
            $job->resetForRetry();
            ProcessFormSubmissionEmail::dispatch($job);
            $count++;
        }

        Flux::toast(variant: 'success', text: __(':count jobs re-queued.', ['count' => $count]));
    }

    /**
     * @return LengthAwarePaginator<int, EmailJob>
     */
    #[Computed]
    public function jobs(): LengthAwarePaginator
    {
        return $this->buildQuery()->paginate(20);
    }

    /**
     * @return Collection<int, Form>
     */
    #[Computed]
    public function forms(): Collection
    {
        return Form::query()->orderBy('name')->get();
    }

    /**
     * @return array<int, array{value: string, label: string, color: string}>
     */
    public function statuses(): array
    {
        return [
            ['value' => 'all', 'label' => __('All'), 'color' => 'zinc'],
            ['value' => EmailJobStatus::Pending->value, 'label' => EmailJobStatus::Pending->label(), 'color' => EmailJobStatus::Pending->color()],
            ['value' => EmailJobStatus::Processing->value, 'label' => EmailJobStatus::Processing->label(), 'color' => EmailJobStatus::Processing->color()],
            ['value' => EmailJobStatus::Sent->value, 'label' => EmailJobStatus::Sent->label(), 'color' => EmailJobStatus::Sent->color()],
            ['value' => EmailJobStatus::Failed->value, 'label' => EmailJobStatus::Failed->label(), 'color' => EmailJobStatus::Failed->color()],
        ];
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        $rows = EmailJob::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        return [
            EmailJobStatus::Pending->value => (int) ($rows[EmailJobStatus::Pending->value] ?? 0),
            EmailJobStatus::Processing->value => (int) ($rows[EmailJobStatus::Processing->value] ?? 0),
            EmailJobStatus::Sent->value => (int) ($rows[EmailJobStatus::Sent->value] ?? 0),
            EmailJobStatus::Failed->value => (int) ($rows[EmailJobStatus::Failed->value] ?? 0),
        ];
    }

    /**
     * @return Builder<EmailJob>
     */
    protected function buildQuery(): Builder
    {
        return EmailJob::query()
            ->with(['submission.form'])
            ->when($this->formFilter !== '', fn ($q) => $q->whereHas('submission.form', fn ($q) => $q->where('slug', $this->formFilter)))
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', function ($q): void {
                $term = strtolower($this->search);
                $q->where(function ($q) use ($term): void {
                    $q->whereRaw('LOWER(recipient) LIKE ?', ['%'.$term.'%'])
                        ->orWhereRaw('LOWER(subject) LIKE ?', ['%'.$term.'%']);
                });
            })
            ->latest('id');
    }

    public function render(): View
    {
        return view('livewire.dashboard.email-jobs');
    }
}
