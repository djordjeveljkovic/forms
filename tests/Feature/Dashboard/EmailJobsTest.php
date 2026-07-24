<?php

namespace Tests\Feature\Dashboard;

use App\Enums\EmailJobStatus;
use App\Jobs\ProcessFormSubmissionEmail;
use App\Livewire\Dashboard\EmailJobs;
use App\Models\EmailJob;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class EmailJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_is_displayed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->get(route('dashboard.email-jobs.index'))->assertOk();
    }

    public function test_jobs_listing_shows_data(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create(['name' => 'Contact']);
        $submission = FormSubmission::factory()->for($form)->create();
        EmailJob::factory()->for($submission, 'submission')->create([
            'recipient' => 'team@example.com',
            'subject' => 'New submission',
        ]);

        Livewire::test(EmailJobs::class)
            ->assertSee('team@example.com')
            ->assertSee('New submission');
    }

    public function test_filter_by_status(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        $submission = FormSubmission::factory()->for($form)->create();

        EmailJob::factory()->for($submission, 'submission')->sent()->create();
        EmailJob::factory()->for($submission, 'submission')->failed()->create();

        Livewire::test(EmailJobs::class)
            ->set('statusFilter', EmailJobStatus::Failed->value)
            ->assertSee(EmailJobStatus::Failed->label());
    }

    public function test_retry_failed_job_dispatches_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        $submission = FormSubmission::factory()->for($form)->create();
        $job = EmailJob::factory()->for($submission, 'submission')->failed()->create();

        Livewire::test(EmailJobs::class)
            ->call('retry', $job->id);

        $this->assertSame(EmailJobStatus::Pending->value, $job->fresh()->status);
        Queue::assertPushed(ProcessFormSubmissionEmail::class);
    }

    public function test_retry_non_failed_job_does_nothing(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        $submission = FormSubmission::factory()->for($form)->create();
        $job = EmailJob::factory()->for($submission, 'submission')->sent()->create();

        Livewire::test(EmailJobs::class)
            ->call('retry', $job->id);

        Queue::assertNotPushed(ProcessFormSubmissionEmail::class);
        $this->assertSame(EmailJobStatus::Sent->value, $job->fresh()->status);
    }
}
