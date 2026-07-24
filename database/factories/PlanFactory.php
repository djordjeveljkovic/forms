<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
            'price_cents' => fake()->numberBetween(0, 9900),
            'currency' => 'USD',
            'interval' => Plan::INTERVAL_MONTHLY,
            'max_forms' => 5,
            'max_submissions_per_month' => 1000,
            'features' => ['basic'],
            'is_active' => true,
            'is_default' => false,
            'sort' => 0,
        ];
    }

    /**
     * Default free plan state.
     */
    public function free(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Free',
            'slug' => 'free',
            'price_cents' => 0,
            'max_forms' => 3,
            'max_submissions_per_month' => 100,
            'features' => ['basic'],
            'is_default' => true,
            'sort' => 0,
        ]);
    }

    /**
     * Professional plan state.
     */
    public function pro(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Pro',
            'slug' => 'pro',
            'price_cents' => 1900,
            'max_forms' => 25,
            'max_submissions_per_month' => 10000,
            'features' => ['basic', 'captcha', 'custom_redirect'],
            'sort' => 1,
        ]);
    }

    /**
     * Enterprise plan state.
     */
    public function enterprise(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Enterprise',
            'slug' => 'enterprise',
            'price_cents' => 9900,
            'max_forms' => null,
            'max_submissions_per_month' => null,
            'features' => ['basic', 'captcha', 'custom_redirect', 'sla', 'priority_email'],
            'sort' => 2,
        ]);
    }
}
