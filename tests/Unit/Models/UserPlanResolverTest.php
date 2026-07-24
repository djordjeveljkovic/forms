<?php

namespace Tests\Unit\Models;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserPlanResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => User::ROLE_ADMIN, 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => User::ROLE_USER, 'guard_name' => 'web']);
    }

    public function test_current_plan_returns_active_subscription_plan(): void
    {
        $plan = Plan::factory()->create(['slug' => 'pro']);
        $user = User::factory()->create();
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);

        $this->assertEquals($plan->id, $user->currentPlan()->id);
    }

    public function test_current_plan_falls_back_to_default_plan(): void
    {
        $default = Plan::factory()->create(['slug' => 'free', 'is_default' => true]);
        $user = User::factory()->create();

        $this->assertEquals($default->id, $user->currentPlan()->id);
    }

    public function test_current_plan_returns_null_when_no_default(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->currentPlan());
    }

    public function test_on_plan_returns_true_when_slugs_match(): void
    {
        $plan = Plan::factory()->create(['slug' => 'pro']);
        $user = User::factory()->create();
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);

        $this->assertTrue($user->onPlan('pro'));
        $this->assertFalse($user->onPlan('free'));
    }

    public function test_is_admin_returns_true_for_admin_user(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertTrue($admin->isAdmin());
    }

    public function test_is_admin_returns_false_for_regular_user(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->isAdmin());
    }

    public function test_has_reached_form_limit_returns_false_when_under_limit(): void
    {
        $plan = Plan::factory()->create(['max_forms' => 5]);
        $user = User::factory()->create();
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);

        $this->assertFalse($user->hasReachedFormLimit());
    }

    public function test_has_reached_form_limit_returns_true_when_at_limit(): void
    {
        $plan = Plan::factory()->create(['max_forms' => 1]);
        $user = User::factory()->create();
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);
        Form::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->hasReachedFormLimit());
    }

    public function test_admin_bypasses_form_limit(): void
    {
        $plan = Plan::factory()->create(['max_forms' => 1]);
        $admin = User::factory()->admin()->create();
        Subscription::query()->create([
            'user_id' => $admin->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);
        Form::factory()->create(['user_id' => $admin->id]);

        $this->assertFalse($admin->hasReachedFormLimit());
    }

    public function test_has_reached_monthly_submission_limit(): void
    {
        $plan = Plan::factory()->create(['max_submissions_per_month' => 1]);
        $user = User::factory()->create();
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);

        $form = Form::factory()->create(['user_id' => $user->id]);
        FormSubmission::query()->create([
            'form_id' => $form->id,
            'submission_data' => ['test' => 'data'],
            'status' => 'received',
            'created_at' => now(),
        ]);

        $this->assertTrue($user->hasReachedMonthlySubmissionLimit());
    }
}
