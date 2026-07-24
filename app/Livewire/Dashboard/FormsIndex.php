<?php

namespace App\Livewire\Dashboard;

use App\Models\AuditLog;
use App\Models\Form;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Forms')]
#[Layout('layouts.app')]
class FormsIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    /**
     * Reset pagination when filters change.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Archive a form.
     *
     * Throws `AuthorizationException` (rendered as 403) if the
     * authenticated user does not own the form.
     */
    public function archive(int $formId): void
    {
        $form = Form::query()->findOrFail($formId);
        $this->authorize('archive', $form);

        $form->forceFill([
            'is_archived' => true,
            'archived_at' => now(),
        ])->save();

        $this->audit($form, 'form.archived');

        Flux::toast(variant: 'success', text: __('Form archived.'));
    }

    /**
     * Restore a form from archive.
     */
    public function restore(int $formId): void
    {
        $form = Form::query()->findOrFail($formId);
        $this->authorize('archive', $form);

        $form->forceFill([
            'is_archived' => false,
            'archived_at' => null,
        ])->save();

        $this->audit($form, 'form.restored');

        Flux::toast(variant: 'success', text: __('Form restored.'));
    }

    /**
     * Regenerate the API key for a form.
     */
    public function regenerateApiKey(int $formId): void
    {
        $form = Form::query()->findOrFail($formId);
        $this->authorize('regenerateApiKey', $form);

        $newKey = $form->regenerateApiKey();

        $this->audit($form, 'form.api_key.regenerated');

        $this->dispatch('api-key-regenerated', key: $newKey, name: $form->name);

        Flux::toast(variant: 'success', text: __('New API key generated.'));
    }

    /**
     * The forms list — scoped to the authenticated user.
     *
     * @return LengthAwarePaginator<int, Form>
     */
    #[Computed]
    public function forms(): LengthAwarePaginator
    {
        $query = Form::query()
            ->ownedBy(Auth::user())
            ->withCount('submissions')
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($q): void {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('slug', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->statusFilter === 'active', fn ($q) => $q->where('is_archived', false))
            ->when($this->statusFilter === 'archived', fn ($q) => $q->where('is_archived', true))
            ->latest('id');

        return $query->paginate(15);
    }

    /**
     * Log an audit event.
     */
    protected function audit(Form $form, string $action): void
    {
        try {
            AuditLog::query()->create([
                'user_id' => Auth::id(),
                'action' => $action,
                'auditable_type' => $form->getMorphClass(),
                'auditable_id' => $form->id,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Throwable) {
            // ignore audit failures
        }
    }

    public function render(): View
    {
        return view('livewire.dashboard.forms-index');
    }
}
