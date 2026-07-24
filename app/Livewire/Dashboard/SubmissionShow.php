<?php

namespace App\Livewire\Dashboard;

use App\Enums\SubmissionStatus;
use App\Models\AuditLog;
use App\Models\FormSubmission;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Submission')]
#[Layout('layouts.app')]
class SubmissionShow extends Component
{
    public FormSubmission $submission;

    /**
     * Mount the component.
     */
    public function mount(FormSubmission $submission): void
    {
        // SaaS isolation: only the form's owner may view a submission.
        $this->authorize('view', $submission);

        $this->submission = $submission;

        if ($submission->status === SubmissionStatus::Received->value) {
            $submission->forceFill([
                'status' => SubmissionStatus::Read->value,
                'read_at' => $submission->read_at ?? now(),
            ])->save();
        }
    }

    /**
     * Mark as spam.
     */
    public function markSpam(): void
    {
        $this->submission->forceFill([
            'status' => SubmissionStatus::Spam->value,
        ])->save();
        $this->audit('submission.spam');
        Flux::toast(variant: 'success', text: __('Marked as spam.'));
    }

    /**
     * Mark as read.
     */
    public function markRead(): void
    {
        $this->submission->forceFill([
            'status' => SubmissionStatus::Read->value,
            'read_at' => $this->submission->read_at ?? now(),
        ])->save();
        $this->audit('submission.read');
    }

    /**
     * Archive the submission.
     */
    public function archive(): void
    {
        $this->submission->forceFill([
            'status' => SubmissionStatus::Archived->value,
        ])->save();
        $this->audit('submission.archived');
        Flux::toast(variant: 'success', text: __('Submission archived.'));
    }

    /**
     * Delete the submission.
     */
    public function delete(): void
    {
        $this->audit('submission.deleted');
        $this->submission->delete();
        Flux::toast(variant: 'success', text: __('Submission deleted.'));
        $this->redirectRoute('dashboard.submissions.index', navigate: true);
    }

    /**
     * @return array<int, array{key: string, label: string, value: mixed, formatted: string}>
     */
    #[Computed]
    public function dataRows(): array
    {
        $fieldLabels = $this->submission->form?->fields()
            ->pluck('label', 'name')
            ->all() ?? [];

        return collect($this->submission->submission_data)
            ->map(function ($value, $key) use ($fieldLabels): array {
                if (is_array($value) || is_object($value)) {
                    $formatted = (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                } elseif (is_bool($value)) {
                    $formatted = $value ? 'true' : 'false';
                } elseif (is_null($value)) {
                    $formatted = '—';
                } elseif (is_scalar($value)) {
                    $formatted = (string) $value;
                } else {
                    $formatted = (string) json_encode($value);
                }

                $keyString = (string) $key;
                $label = $fieldLabels[$keyString] ?? ucwords(str_replace(['_', '-'], ' ', $keyString));

                return [
                    'key' => $keyString,
                    'label' => $label,
                    'value' => $value,
                    'formatted' => $formatted,
                ];
            })
            ->values()
            ->all();
    }

    protected function audit(string $action): void
    {
        try {
            AuditLog::query()->create([
                'user_id' => Auth::id(),
                'action' => $action,
                'auditable_type' => $this->submission->getMorphClass(),
                'auditable_id' => $this->submission->id,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Throwable) {
            // ignore
        }
    }

    public function render(): View
    {
        return view('livewire.dashboard.submission-show');
    }
}
