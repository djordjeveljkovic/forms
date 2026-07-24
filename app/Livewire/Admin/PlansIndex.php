<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin view of all subscription plans.
 */
#[Title('Admin · Plans')]
#[Layout('layouts.admin')]
class PlansIndex extends Component
{
    /**
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function plans()
    {
        return Plan::query()
            ->withCount(['subscriptions', 'activeSubscriptions'])
            ->ordered()
            ->get();
    }

    /**
     * Toggle the active state of a plan.
     */
    public function toggleActive(int $planId): void
    {
        $caller = Auth::user();
        abort_unless($caller->can('manage-plans'), 403);

        $plan = Plan::query()->findOrFail($planId);
        $plan->update(['is_active' => ! $plan->is_active]);

        $this->audit('plan.updated', [
            'plan_id' => $plan->getKey(),
            'plan_slug' => $plan->slug,
            'is_active' => $plan->is_active,
        ]);

        Flux::toast(variant: 'success', text: $plan->is_active ? __('Plan activated.') : __('Plan deactivated.'));
    }

    /**
     * Delete a plan (refuses if any subscription is using it).
     */
    public function delete(int $planId): void
    {
        $caller = Auth::user();
        abort_unless($caller->can('manage-plans'), 403);

        $plan = Plan::query()->findOrFail($planId);

        $subCount = Subscription::query()->where('plan_id', $plan->getKey())->count();

        if ($subCount > 0) {
            Flux::toast(
                variant: 'danger',
                text: __('Cannot delete plan with :count subscriptions.', ['count' => $subCount]),
            );

            return;
        }

        $this->audit('plan.deleted', [
            'plan_id' => $plan->getKey(),
            'plan_slug' => $plan->slug,
        ]);

        $plan->delete();

        Flux::toast(variant: 'success', text: __('Plan deleted.'));
    }

    /**
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
            // ignore
        }
    }

    public function render(): View
    {
        return view('livewire.admin.plans-index');
    }
}
