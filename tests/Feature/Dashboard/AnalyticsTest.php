<?php

namespace Tests\Feature\Dashboard;

use App\Enums\EmailJobStatus;
use App\Livewire\Dashboard\Analytics;
use App\Models\EmailJob;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_page_is_displayed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->get(route('dashboard.index'))->assertOk();
    }

    public function test_analytics_counts_submissions_in_range(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        FormSubmission::factory()->count(3)->for($form)->create();

        Livewire::test(Analytics::class)
            ->assertSet('range', '30d')
            ->assertSee('3');
    }

    public function test_analytics_counts_active_forms(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Form::factory()->count(2)->create();
        Form::factory()->ownedBy($user)->archived()->create();

        Livewire::test(Analytics::class)
            ->assertSee('2');
    }

    public function test_analytics_counts_email_statuses(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        $submission = FormSubmission::factory()->for($form)->create();
        EmailJob::factory()->for($submission, 'submission')->sent()->create();
        EmailJob::factory()->for($submission, 'submission')->failed()->create();

        Livewire::test(Analytics::class)
            ->assertSee(EmailJobStatus::Sent->label())
            ->assertSee(EmailJobStatus::Failed->label());
    }

    public function test_range_can_be_changed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(Analytics::class)
            ->call('setRange', '7d')
            ->assertSet('range', '7d');
    }

    public function test_invalid_range_is_ignored(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(Analytics::class)
            ->call('setRange', 'bogus')
            ->assertSet('range', '30d');
    }
}
