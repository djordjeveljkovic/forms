<?php

namespace Tests\Feature\Dashboard;

use App\Enums\SubmissionStatus;
use App\Livewire\Dashboard\SubmissionsIndex;
use App\Models\EmailJob;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubmissionsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_is_displayed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->get(route('dashboard.submissions.index'))->assertOk();
    }

    public function test_submissions_listing_shows_data(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create(['name' => 'Contact']);
        FormSubmission::factory()->for($form)->create([
            'submission_data' => ['name' => 'Jane', 'email' => 'jane@example.com'],
        ]);

        Livewire::test(SubmissionsIndex::class)
            ->assertSee('Contact')
            ->assertSee('Jane');
    }

    public function test_filter_by_form(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $contact = Form::factory()->ownedBy($user)->create(['name' => 'ContactForm']);
        $newsletter = Form::factory()->ownedBy($user)->create(['name' => 'NewsletterForm']);

        FormSubmission::factory()->for($contact)->create();
        FormSubmission::factory()->for($newsletter)->create();

        Livewire::test(SubmissionsIndex::class)
            ->set('formFilter', $contact->slug)
            ->tap(fn ($component) => $this->assertCount(1, $component->submissions))
            ->tap(fn ($component) => $this->assertSame($contact->id, $component->submissions->first()->form_id));
    }

    public function test_filter_by_status(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->ownedBy($user)->create();

        FormSubmission::factory()->for($form)->create();
        FormSubmission::factory()->for($form)->read()->create();

        Livewire::test(SubmissionsIndex::class)
            ->set('statusFilter', SubmissionStatus::Read->value)
            ->assertSee(SubmissionStatus::Read->label());
    }

    public function test_filter_by_delivery_status(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        $sent = FormSubmission::factory()->for($form)->create();
        $failed = FormSubmission::factory()->for($form)->create();

        EmailJob::factory()->for($sent, 'submission')->sent()->create();
        EmailJob::factory()->for($failed, 'submission')->failed()->create();

        Livewire::test(SubmissionsIndex::class)
            ->set('deliveryFilter', 'failed')
            ->assertSee('Failed');
    }

    public function test_mark_read(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $submission = FormSubmission::factory()->forFormOwnedBy($user)->create();

        Livewire::test(SubmissionsIndex::class)
            ->call('markRead', $submission->id);

        $this->assertSame(SubmissionStatus::Read->value, $submission->fresh()->status);
        $this->assertNotNull($submission->fresh()->read_at);
    }

    public function test_mark_spam(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $submission = FormSubmission::factory()->forFormOwnedBy($user)->create();

        Livewire::test(SubmissionsIndex::class)
            ->call('markSpam', $submission->id);

        $this->assertSame(SubmissionStatus::Spam->value, $submission->fresh()->status);
    }

    public function test_clear_filters(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->ownedBy($user)->create();

        Livewire::test(SubmissionsIndex::class)
            ->set('formFilter', $form->slug)
            ->set('search', 'foo')
            ->call('clearFilters')
            ->assertSet('formFilter', '')
            ->assertSet('search', '');
    }
}
