<?php

namespace Tests\Unit\Models;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_formatted_price_returns_free_label(): void
    {
        $plan = Plan::factory()->create([
            'price_cents' => 0,
            'currency' => 'USD',
            'interval' => Plan::INTERVAL_MONTHLY,
        ]);

        $this->assertStringContainsString('0.00', $plan->formattedPrice());
        $this->assertStringContainsString('$', $plan->formattedPrice());
    }

    public function test_formatted_price_uses_currency_symbol(): void
    {
        $plan = Plan::factory()->create([
            'price_cents' => 1900,
            'currency' => 'EUR',
            'interval' => Plan::INTERVAL_MONTHLY,
        ]);

        $this->assertStringContainsString('€', $plan->formattedPrice());
        $this->assertStringContainsString('19.00', $plan->formattedPrice());
    }

    public function test_has_unlimited_forms_returns_true_when_null(): void
    {
        $plan = Plan::factory()->create(['max_forms' => null]);
        $this->assertTrue($plan->hasUnlimitedForms());
    }

    public function test_has_unlimited_forms_returns_false_when_set(): void
    {
        $plan = Plan::factory()->create(['max_forms' => 5]);
        $this->assertFalse($plan->hasUnlimitedForms());
    }

    public function test_has_unlimited_submissions_returns_true_when_null(): void
    {
        $plan = Plan::factory()->create(['max_submissions_per_month' => null]);
        $this->assertTrue($plan->hasUnlimitedSubmissions());
    }

    public function test_features_is_cast_to_array(): void
    {
        $plan = Plan::factory()->create(['features' => ['basic', 'captcha']]);
        $this->assertIsArray($plan->features);
        $this->assertEquals(['basic', 'captcha'], $plan->features);
    }

    public function test_active_scope_filters_inactive(): void
    {
        Plan::factory()->create(['is_active' => true]);
        Plan::factory()->create(['is_active' => false]);

        $this->assertEquals(1, Plan::query()->active()->count());
    }

    public function test_ordered_scope_orders_by_sort(): void
    {
        Plan::factory()->create(['name' => 'C', 'sort' => 3]);
        Plan::factory()->create(['name' => 'A', 'sort' => 1]);
        Plan::factory()->create(['name' => 'B', 'sort' => 2]);

        $names = Plan::query()->ordered()->pluck('name')->all();
        $this->assertEquals(['A', 'B', 'C'], $names);
    }
}
