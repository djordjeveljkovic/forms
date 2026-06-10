<?php

namespace App\Livewire\Dashboard;

use App\Enums\EmailJobStatus;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Title('Submissions')]
#[Layout('layouts.app')]
class SubmissionsIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'form', except: '')]
    public string $formFilter = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    #[Url(as: 'delivery', except: 'all')]
    public string $deliveryFilter = 'all';

    #[Url(as: 'from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'to', except: '')]
    public string $dateTo = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFormFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDeliveryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'formFilter', 'statusFilter', 'deliveryFilter', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    public function markRead(int $submissionId): void
    {
        $submission = FormSubmission::query()->findOrFail($submissionId);
        $submission->forceFill([
            'status' => SubmissionStatus::Read->value,
            'read_at' => $submission->read_at ?? now(),
        ])->save();
    }

    public function markSpam(int $submissionId): void
    {
        $submission = FormSubmission::query()->findOrFail($submissionId);
        $submission->forceFill([
            'status' => SubmissionStatus::Spam->value,
        ])->save();
    }

    /**
     * Get filtered submissions.
     *
     * @return LengthAwarePaginator<int, FormSubmission>
     */
    #[Computed]
    public function submissions(): LengthAwarePaginator
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
     * @return array<int, array{value: string, label: string}>
     */
    public function statuses(): array
    {
        return [
            ['value' => 'all', 'label' => __('All')],
            ['value' => SubmissionStatus::Received->value, 'label' => SubmissionStatus::Received->label()],
            ['value' => SubmissionStatus::Read->value, 'label' => SubmissionStatus::Read->label()],
            ['value' => SubmissionStatus::Spam->value, 'label' => SubmissionStatus::Spam->label()],
            ['value' => SubmissionStatus::Archived->value, 'label' => SubmissionStatus::Archived->label()],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function deliveryOptions(): array
    {
        return [
            ['value' => 'all', 'label' => __('All')],
            ['value' => 'sent', 'label' => __('Sent')],
            ['value' => 'pending', 'label' => __('Pending / Processing')],
            ['value' => 'failed', 'label' => __('Failed')],
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        $filename = 'form-submissions-'.now()->format('Y-m-d-His').'.csv';
        $submissions = $this->buildExportQuery()->get();

        return response()->streamDownload(function () use ($submissions): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }
            fputcsv($handle, ['ID', 'Form', 'Status', 'IP', 'User Agent', 'Referer', 'Created At', 'Data']);

            foreach ($submissions as $submission) {
                fputcsv($handle, [
                    $submission->id,
                    $submission->form?->name,
                    $submission->status,
                    $submission->ip_address,
                    $submission->user_agent,
                    $submission->referer,
                    $submission->created_at?->toIso8601String(),
                    json_encode($submission->submission_data, JSON_UNESCAPED_SLASHES),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Build the list query with all filters.
     *
     * @return Builder<FormSubmission>
     */
    protected function buildQuery(): Builder
    {
        return FormSubmission::query()
            ->with(['form', 'emailJobs'])
            ->when($this->formFilter !== '', fn ($q) => $q->whereHas('form', fn ($q) => $q->where('slug', $this->formFilter)))
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', function ($q): void {
                $term = strtolower($this->search);
                $driver = DB::connection()->getDriverName();
                if ($driver === 'sqlite') {
                    $q->whereRaw('LOWER(CAST(submission_data AS TEXT)) LIKE ?', ['%'.$term.'%']);
                } else {
                    $q->whereRaw('LOWER(CAST(submission_data AS CHAR)) LIKE ?', ['%'.$term.'%']);
                }
            })
            ->when($this->dateFrom !== '', fn ($q) => $q->where('created_at', '>=', Carbon::parse($this->dateFrom)->startOfDay()))
            ->when($this->dateTo !== '', fn ($q) => $q->where('created_at', '<=', Carbon::parse($this->dateTo)->endOfDay()))
            ->when($this->deliveryFilter !== 'all', function ($q): void {
                $q->whereHas('emailJobs', function ($q): void {
                    match ($this->deliveryFilter) {
                        'sent' => $q->where('status', EmailJobStatus::Sent->value),
                        'failed' => $q->where('status', EmailJobStatus::Failed->value),
                        'pending' => $q->whereIn('status', [EmailJobStatus::Pending->value, EmailJobStatus::Processing->value]),
                        default => null,
                    };
                });
            })
            ->latest('id');
    }

    /**
     * Build the export query (subset of filters).
     *
     * @return Builder<FormSubmission>
     */
    protected function buildExportQuery(): Builder
    {
        return FormSubmission::query()
            ->with('form')
            ->when($this->formFilter !== '', fn ($q) => $q->whereHas('form', fn ($q) => $q->where('slug', $this->formFilter)))
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFrom !== '', fn ($q) => $q->where('created_at', '>=', Carbon::parse($this->dateFrom)->startOfDay()))
            ->when($this->dateTo !== '', fn ($q) => $q->where('created_at', '<=', Carbon::parse($this->dateTo)->endOfDay()))
            ->latest('id');
    }

    public function render(): View
    {
        return view('livewire.dashboard.submissions-index');
    }
}
