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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * Admin form for creating a new user.
 */
#[Title('Admin · Create user')]
#[Layout('layouts.admin')]
class UserCreate extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|email|max:255|unique:users,email')]
    public string $email = '';

    #[Validate('required|string|min:8')]
    public string $password = '';

    public bool $isAdmin = false;

    public bool $sendVerification = true;

    public ?int $planId = null;

    /**
     * Randomly generate a password.
     */
    public function generatePassword(): void
    {
        $this->password = Str::password(16);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'isAdmin' => ['boolean'],
            'planId' => ['nullable', 'integer', Rule::exists('plans', 'id')],
        ];
    }

    /**
     * Save the new user.
     */
    public function save(): void
    {
        $caller = Auth::user();
        abort_unless($caller->can('create-users'), 403);

        $data = $this->validate();

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'email_verified_at' => $this->sendVerification ? now() : null,
        ]);

        if ($this->isAdmin) {
            $adminRole = Role::query()->firstOrCreate(['name' => User::ROLE_ADMIN, 'guard_name' => 'web']);
            $user->assignRole($adminRole);
        } else {
            $userRole = Role::query()->firstOrCreate(['name' => User::ROLE_USER, 'guard_name' => 'web']);
            $user->assignRole($userRole);
        }

        $plan = $this->resolvePlan($data['planId'] ?? null);

        if ($plan !== null) {
            Subscription::query()->create([
                'user_id' => $user->getKey(),
                'plan_id' => $plan->getKey(),
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => now(),
            ]);
        }

        AuditLog::query()->create([
            'user_id' => $caller->getKey(),
            'action' => 'user.created',
            'metadata' => [
                'new_user_id' => $user->getKey(),
                'new_user_email' => $user->email,
                'is_admin' => $this->isAdmin,
                'plan_slug' => $plan?->slug,
            ],
            'ip_address' => request()->ip(),
        ]);

        Flux::toast(variant: 'success', text: __('User created.'));

        $this->redirectRoute('admin.users.show', ['user' => $user->getKey()], navigate: true);
    }

    /**
     * Resolve the plan to assign — fall back to the system default.
     */
    protected function resolvePlan(?int $planId): ?Plan
    {
        if ($planId !== null) {
            return Plan::query()->find($planId);
        }

        return Plan::query()->where('is_default', true)->first()
            ?? Plan::query()->where('slug', 'free')->first();
    }

    /**
     * @return Collection<int, Plan>
     */
    public function plans()
    {
        return Plan::query()->ordered()->get();
    }

    public function render(): View
    {
        return view('livewire.admin.user-create');
    }
}
