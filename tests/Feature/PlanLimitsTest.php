<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\FormCreate;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => User::ROLE_ADMIN, 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => User::ROLE_USER, 'guard_name' => 'web']);
    }

    public function test_user_can_create_form_when_under_limit(): void
    {
        $plan = Plan::factory()->create(['max_forms' => 3]);
        $user = User::factory()->create();
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(FormCreate::class)
            ->set('name', 'Test Form')
            ->set('recipientEmails.0', 'admin@example.com')
            ->set('subjectTemplate', 'Sub')
            ->set('successMessage', 'Thanks')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('forms', ['name' => 'Test Form', 'user_id' => $user->id]);
    }

    public function test_user_cannot_create_form_when_at_limit(): void
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

        Livewire::actingAs($user)
            ->test(FormCreate::class)
            ->set('name', 'Test Form')
            ->set('recipientEmails.0', 'admin@example.com')
            ->set('subjectTemplate', 'Sub')
            ->set('successMessage', 'Thanks')
            ->call('save')
            ->assertHasErrors(['name']);

        $this->assertEquals(1, Form::query()->where('user_id', $user->id)->count());
    }

    public function test_admin_bypasses_form_limit(): void
    {
        $plan = Plan::factory()->create(['max_forms' => 1]);
        $admin = User::factory()->admin()->create();
        Form::factory()->create(['user_id' => $admin->id]);

        Livewire::actingAs($admin)
            ->test(FormCreate::class)
            ->set('name', 'Admin Form')
            ->set('recipientEmails.0', 'admin@example.com')
            ->set('subjectTemplate', 'Sub')
            ->set('successMessage', 'Thanks')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_user_can_submit_when_under_monthly_limit(): void
    {
        $plan = Plan::factory()->create(['max_submissions_per_month' => 5]);
        $user = User::factory()->create();
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);

        $form = Form::factory()->create(['user_id' => $user->id]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'email' => 'test@example.com',
            'message' => 'Hello',
        ], [
            'X-Form-Key' => $form->api_key,
        ]);

        // When under the limit, the submission succeeds (201) — the
        // form has no fields configured so the submission is accepted.
        $response->assertStatus(201);
    }

    public function test_user_blocked_when_at_monthly_limit(): void
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

        // Pre-insert a submission to put the user at the limit
        FormSubmission::query()->create([
            'form_id' => $form->id,
            'submission_data' => ['test' => 'data'],
            'status' => 'received',
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'email' => 'test@example.com',
            'message' => 'Hello',
        ], [
            'X-Form-Key' => $form->api_key,
        ]);

        $response->assertStatus(429);
    }

    public function test_admin_bypasses_monthly_limit(): void
    {
        $plan = Plan::factory()->create(['max_submissions_per_month' => 1]);
        $admin = User::factory()->admin()->create();
        $form = Form::factory()->create(['user_id' => $admin->id]);

        FormSubmission::query()->create([
            'form_id' => $form->id,
            'submission_data' => ['test' => 'data'],
            'status' => 'received',
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'email' => 'test@example.com',
            'message' => 'Hello',
        ], [
            'X-Form-Key' => $form->api_key,
        ]);

        // Should not be 429 (limit blocked)
        $this->assertNotEquals(429, $response->status());
    }
}
