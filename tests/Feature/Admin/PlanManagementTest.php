<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\PlanCreate;
use App\Livewire\Admin\PlanEdit;
use App\Livewire\Admin\PlansIndex;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlanManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => User::ROLE_ADMIN, 'guard_name' => 'web']);
    }

    public function test_admin_can_list_plans(): void
    {
        $admin = User::factory()->admin()->create();
        Plan::factory()->count(3)->create();

        $component = Livewire::actingAs($admin)
            ->test(PlansIndex::class);

        $this->assertCount(3, $component->plans);
    }

    public function test_admin_can_create_plan(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(PlanCreate::class)
            ->set('name', 'Premium')
            ->set('slug', 'premium')
            ->set('priceCents', 4900)
            ->set('currency', 'USD')
            ->set('interval', Plan::INTERVAL_MONTHLY)
            ->set('maxForms', 100)
            ->set('maxSubmissionsPerMonth', 50000)
            ->set('features', ['basic', 'priority'])
            ->set('isActive', true)
            ->set('isDefault', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.plans.index'));

        $this->assertDatabaseHas('plans', [
            'slug' => 'premium',
            'price_cents' => 4900,
        ]);
    }

    public function test_only_one_plan_can_be_default(): void
    {
        $admin = User::factory()->admin()->create();
        $old = Plan::factory()->free()->create();
        $new = Plan::factory()->create();

        Livewire::actingAs($admin)
            ->test(PlanEdit::class, ['plan' => $new])
            ->set('name', $new->name)
            ->set('slug', $new->slug)
            ->set('priceCents', $new->price_cents)
            ->set('currency', $new->currency)
            ->set('interval', $new->interval)
            ->set('isActive', $new->is_active)
            ->set('isDefault', true)
            ->set('sort', $new->sort)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($old->fresh()->is_default);
        $this->assertTrue($new->fresh()->is_default);
    }

    public function test_admin_can_delete_plan_with_no_subscriptions(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();

        Livewire::actingAs($admin)
            ->test(PlansIndex::class)
            ->call('delete', $plan->id);

        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
    }

    public function test_admin_cannot_delete_plan_with_subscriptions(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();
        $user = User::factory()->create();

        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(PlansIndex::class)
            ->call('delete', $plan->id);

        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
    }

    public function test_admin_can_toggle_plan_active(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create(['is_active' => true]);

        Livewire::actingAs($admin)
            ->test(PlansIndex::class)
            ->call('toggleActive', $plan->id);

        $this->assertFalse($plan->fresh()->is_active);
    }

    public function test_non_admin_cannot_access_admin_plans(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.plans.index'))
            ->assertForbidden();
    }
}
