<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * Admin form for editing an existing user.
 */
#[Title('Admin · Edit user')]
#[Layout('layouts.admin')]
class UserEdit extends Component
{
    public User $user;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|email|max:255')]
    public string $email = '';

    public string $password = '';

    public bool $isAdmin = false;

    public ?int $planId = null;

    public bool $resetTwoFactor = false;

    public bool $forcePasswordReset = false;

    public string $generatedPassword = '';

    /**
     * Mount the component.
     */
    public function mount(User $user): void
    {
        $this->user = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->isAdmin = $user->isAdmin();
        $this->planId = $user->activeSubscription?->plan_id;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user->getKey())],
            'password' => ['nullable', 'string', 'min:8'],
            'isAdmin' => ['boolean'],
            'planId' => ['nullable', 'integer', Rule::exists('plans', 'id')],
        ];
    }

    /**
     * Persist the changes.
     */
    public function save(): void
    {
        $caller = Auth::user();
        abort_unless($caller->can('edit-users'), 403);

        $data = $this->validate();

        $caller = Auth::user();
        $originalEmail = $this->user->email;

        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
        ];

        if (strtolower($data['email']) !== strtolower($originalEmail)) {
            $payload['email_verified_at'] = null;
        }

        if ($this->forcePasswordReset && $this->password !== '') {
            $payload['password'] = Hash::make($this->password);
            $this->generatedPassword = $this->password;
        }

        $this->user->update($payload);

        // Role change
        $adminRole = Role::query()->firstOrCreate(['name' => User::ROLE_ADMIN, 'guard_name' => 'web']);
        $userRole = Role::query()->firstOrCreate(['name' => User::ROLE_USER, 'guard_name' => 'web']);

        if ($this->isAdmin && ! $this->user->hasRole($adminRole->name)) {
            $this->user->assignRole($adminRole);
            $this->audit('user.role.assigned', ['target_user_id' => $this->user->getKey()]);
        } elseif (! $this->isAdmin && $this->user->hasRole($adminRole->name)) {
            // Don't allow self-demotion
            if ($this->user->getKey() === $caller->getKey()) {
                Flux::toast(variant: 'danger', text: __('You cannot demote yourself.'));

                return;
            }
            $this->user->removeRole($adminRole);
            $this->audit('user.role.removed', ['target_user_id' => $this->user->getKey()]);
        }

        if (! $this->isAdmin && ! $this->user->hasRole($userRole->name)) {
            $this->user->assignRole($userRole);
        }

        // Plan change
        $activeSub = $this->user->activeSubscription()->first();
        $currentPlanId = $activeSub?->plan_id;

        if ($this->planId !== $currentPlanId) {
            $newPlan = $this->planId !== null ? Plan::query()->find($this->planId) : null;

            if ($activeSub !== null) {
                $activeSub->update([
                    'status' => Subscription::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ]);
            }

            if ($newPlan !== null) {
                Subscription::query()->create([
                    'user_id' => $this->user->getKey(),
                    'plan_id' => $newPlan->getKey(),
                    'status' => Subscription::STATUS_ACTIVE,
                    'starts_at' => now(),
                ]);
            }

            $this->audit('user.plan.changed', [
                'target_user_id' => $this->user->getKey(),
                'new_plan_slug' => $newPlan?->slug,
            ]);
        }

        // 2FA reset
        if ($this->resetTwoFactor) {
            $this->user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
            $this->audit('user.2fa.disabled', ['target_user_id' => $this->user->getKey()]);
            $this->resetTwoFactor = false;
        }

        $this->audit('user.updated', [
            'target_user_id' => $this->user->getKey(),
            'force_password_reset' => $this->forcePasswordReset,
        ]);

        $this->forcePasswordReset = false;
        $this->password = '';

        Flux::toast(variant: 'success', text: __('User updated.'));

        if ($this->generatedPassword !== '') {
            Flux::toast(
                variant: 'warning',
                text: __('New password (copy now): :pwd', ['pwd' => $this->generatedPassword]),
                duration: 30000,
            );
            $this->generatedPassword = '';
        }
    }

    /**
     * @return Collection<int, Plan>
     */
    public function plans()
    {
        return Plan::query()->ordered()->get();
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
        return view('livewire.admin.user-edit');
    }
}
