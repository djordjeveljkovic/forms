<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            PlanSeeder::class,
        ]);

        $defaultPlan = Plan::query()->where('is_default', true)->first()
            ?? Plan::query()->where('slug', 'free')->first();

        User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Site Administrator',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );

        $admin = User::query()->where('email', 'admin@example.com')->first();
        $adminRole = Role::query()->firstOrCreate(['name' => User::ROLE_ADMIN, 'guard_name' => 'web']);
        if (! $admin->hasRole($adminRole->name)) {
            $admin->assignRole($adminRole);
        }

        $this->ensureSubscription($admin, $defaultPlan);

        User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );

        $testUser = User::query()->where('email', 'test@example.com')->first();
        $userRole = Role::query()->firstOrCreate(['name' => User::ROLE_USER, 'guard_name' => 'web']);
        if (! $testUser->hasRole($userRole->name)) {
            $testUser->assignRole($userRole);
        }

        $this->ensureSubscription($testUser, $defaultPlan);

        $this->call(FormSeeder::class);
    }

    /**
     * Make sure the user has at least one active subscription. If
     * they don't, attach them to the given plan (or the default plan
     * if null).
     */
    protected function ensureSubscription(User $user, ?Plan $plan): void
    {
        if ($plan === null) {
            return;
        }

        $hasActive = Subscription::query()
            ->forUser($user->getKey())
            ->active()
            ->exists();

        if ($hasActive) {
            return;
        }

        Subscription::query()->create([
            'user_id' => $user->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => null,
        ]);
    }
}
