<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_id' => Plan::factory(),
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => null,
            'trial_ends_at' => null,
            'cancelled_at' => null,
            'metadata' => null,
        ];
    }

    /**
     * Active subscription.
     */
    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
        ]);
    }

    /**
     * Cancelled subscription.
     */
    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * For a specific user.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->getKey(),
        ]);
    }

    /**
     * On a specific plan.
     */
    public function onPlan(Plan $plan): static
    {
        return $this->state(fn (): array => [
            'plan_id' => $plan->getKey(),
        ]);
    }
}
