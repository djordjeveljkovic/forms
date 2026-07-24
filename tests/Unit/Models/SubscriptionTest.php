<?php

namespace Tests\Unit\Models;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_active_returns_true_for_active_status(): void
    {
        $sub = Subscription::factory()->active()->create();
        $this->assertTrue($sub->isActive());
    }

    public function test_is_active_returns_false_for_cancelled(): void
    {
        $sub = Subscription::factory()->cancelled()->create();
        $this->assertFalse($sub->isActive());
    }

    public function test_is_active_returns_false_when_past_end_date(): void
    {
        $sub = Subscription::factory()->create([
            'status' => Subscription::STATUS_ACTIVE,
            'ends_at' => now()->subDay(),
        ]);
        $this->assertFalse($sub->isActive());
    }

    public function test_active_scope_excludes_cancelled(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->active()->create(['user_id' => $user->id]);
        Subscription::factory()->cancelled()->create(['user_id' => $user->id]);

        $this->assertEquals(1, Subscription::query()->active()->forUser($user->id)->count());
    }

    public function test_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $sub = Subscription::factory()->create(['user_id' => $user->id]);

        $this->assertEquals($user->id, $sub->user->id);
    }

    public function test_belongs_to_plan(): void
    {
        $plan = Plan::factory()->create();
        $sub = Subscription::factory()->create(['plan_id' => $plan->id]);

        $this->assertEquals($plan->id, $sub->plan->id);
    }
}
