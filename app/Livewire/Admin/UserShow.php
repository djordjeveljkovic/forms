<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Form;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only admin drill-down on a user.
 */
#[Title('Admin · User details')]
#[Layout('layouts.admin')]
class UserShow extends Component
{
    use WithPagination;

    public User $user;

    /**
     * Mount the component.
     */
    public function mount(User $user): void
    {
        $this->user = $user;
    }

    #[Computed]
    public function currentPlan()
    {
        return $this->user->currentPlan();
    }

    #[Computed]
    public function subscriptions()
    {
        return $this->user->subscriptions()->with('plan')->get();
    }

    /**
     * @return LengthAwarePaginator<int, Form>
     */
    #[Computed]
    public function forms(): LengthAwarePaginator
    {
        return Form::query()
            ->where('user_id', $this->user->getKey())
            ->withCount('submissions')
            ->latest('id')
            ->paginate(10);
    }

    /**
     * @return Collection<int, AuditLog>
     */
    #[Computed]
    public function auditLog()
    {
        return AuditLog::query()
            ->where('user_id', $this->user->getKey())
            ->latest('created_at')
            ->limit(20)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.admin.user-show');
    }
}
