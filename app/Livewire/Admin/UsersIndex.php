<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin user list with search + filters.
 */
#[Title('Admin · Users')]
#[Layout('layouts.admin')]
class UsersIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'role', except: 'all')]
    public string $roleFilter = 'all';

    #[Url(as: 'plan', except: 'all')]
    public string $planFilter = 'all';

    #[Url(as: 'verified', except: 'all')]
    public string $verifiedFilter = 'all';

    #[Url(as: 'sort', except: 'latest')]
    public string $sort = 'latest';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPlanFilter(): void
    {
        $this->resetPage();
    }

    public function updatedVerifiedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    /**
     * Paginated users matching the current filters.
     *
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $query = User::query()
            ->with(['activeSubscription.plan', 'roles'])
            ->withCount(['forms', 'formSubmissions']);

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%');
            });
        }

        if ($this->roleFilter === 'admin') {
            $query->role(User::ROLE_ADMIN);
        } elseif ($this->roleFilter === 'user') {
            $query->whereDoesntHave('roles', function ($q): void {
                $q->where('name', User::ROLE_ADMIN);
            });
        }

        if ($this->planFilter !== 'all') {
            $query->whereHas('activeSubscription.plan', function ($q): void {
                $q->where('slug', $this->planFilter);
            });
        }

        if ($this->verifiedFilter === 'yes') {
            $query->whereNotNull('email_verified_at');
        } elseif ($this->verifiedFilter === 'no') {
            $query->whereNull('email_verified_at');
        }

        $query->when($this->sort === 'latest', fn ($q) => $q->latest('id'))
            ->when($this->sort === 'oldest', fn ($q) => $q->oldest('id'))
            ->when($this->sort === 'name', fn ($q) => $q->orderBy('name'))
            ->when($this->sort === 'forms', fn ($q) => $q->orderByDesc('forms_count'))
            ->when($this->sort === 'submissions', fn ($q) => $q->orderByDesc('form_submissions_count'));

        return $query->paginate(20);
    }

    /**
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function plans()
    {
        return Plan::query()->ordered()->get();
    }

    /**
     * Delete a user (admins cannot delete themselves).
     */
    public function delete(int $userId): void
    {
        $caller = Auth::user();
        abort_unless($caller->can('delete-users'), 403);

        $user = User::query()->findOrFail($userId);

        if ($user->getKey() === $caller->getKey()) {
            Flux::toast(variant: 'danger', text: __('You cannot delete yourself.'));

            return;
        }

        $this->audit('user.deleted', [
            'deleted_user_id' => $user->getKey(),
            'deleted_user_email' => $user->email,
        ]);

        $user->delete();

        Flux::toast(variant: 'success', text: __('User deleted.'));
    }

    /**
     * Toggle the admin role on a user.
     */
    public function toggleAdmin(int $userId): void
    {
        $caller = Auth::user();
        abort_unless($caller->can('edit-users'), 403);

        $user = User::query()->findOrFail($userId);

        if ($user->getKey() === $caller->getKey() && $user->isAdmin()) {
            Flux::toast(variant: 'danger', text: __('You cannot demote yourself.'));

            return;
        }

        if ($user->isAdmin()) {
            $user->removeRole(User::ROLE_ADMIN);
            $action = 'user.role.removed';
            $message = __('Admin role removed.');
        } else {
            $user->assignRole(User::ROLE_ADMIN);
            $action = 'user.role.assigned';
            $message = __('User promoted to admin.');
        }

        $this->audit($action, [
            'target_user_id' => $user->getKey(),
            'target_user_email' => $user->email,
        ]);

        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * Log an audit event.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function audit(string $action, array $metadata = []): void
    {
        try {
            AuditLog::query()->create([
                'user_id' => Auth::id(),
                'action' => $action,
                'metadata' => $metadata,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Throwable) {
            // ignore audit failures
        }
    }

    public function render(): View
    {
        return view('livewire.admin.users-index');
    }
}
