<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Seeds the standard subscription plans.
 *
 * Three tiers: Free (default), Pro, Enterprise.
 * Plans are matched by slug and updated in place so the seeder is
 * idempotent — re-running it does not duplicate rows.
 */
class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Make sure only one plan is the default at any time.
        $hasDefault = Plan::query()->where('is_default', true)->exists();

        Plan::query()->updateOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'description' => 'Get started with the essentials.',
                'price_cents' => 0,
                'currency' => 'USD',
                'interval' => Plan::INTERVAL_MONTHLY,
                'max_forms' => 3,
                'max_submissions_per_month' => 100,
                'features' => ['basic'],
                'is_active' => true,
                'is_default' => ! $hasDefault,
                'sort' => 0,
            ],
        );

        Plan::query()->updateOrCreate(
            ['slug' => 'pro'],
            [
                'name' => 'Pro',
                'description' => 'For teams that need more forms and protection.',
                'price_cents' => 1900,
                'currency' => 'USD',
                'interval' => Plan::INTERVAL_MONTHLY,
                'max_forms' => 25,
                'max_submissions_per_month' => 10000,
                'features' => ['basic', 'captcha', 'custom_redirect'],
                'is_active' => true,
                'is_default' => false,
                'sort' => 1,
            ],
        );

        Plan::query()->updateOrCreate(
            ['slug' => 'enterprise'],
            [
                'name' => 'Enterprise',
                'description' => 'Unlimited forms, submissions, and priority support.',
                'price_cents' => 9900,
                'currency' => 'USD',
                'interval' => Plan::INTERVAL_MONTHLY,
                'max_forms' => null,
                'max_submissions_per_month' => null,
                'features' => ['basic', 'captcha', 'custom_redirect', 'sla', 'priority_email'],
                'is_active' => true,
                'is_default' => false,
                'sort' => 2,
            ],
        );
    }
}
