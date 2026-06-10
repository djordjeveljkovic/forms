<?php

namespace Tests\Feature\Jobs;

use App\Enums\EmailJobStatus;
use App\Jobs\ProcessFormSubmissionEmail;
use App\Mail\FormSubmissionMail;
use App\Models\EmailJob;
use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProcessFormSubmissionEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_marks_email_as_sent_when_mail_is_delivered(): void
    {
        Mail::fake();

        $form = Form::factory()->create();
        $submission = FormSubmission::factory()->for($form)->create();
        $emailJob = EmailJob::factory()->for($submission, 'submission')->create([
            'status' => EmailJobStatus::Pending->value,
        ]);

        (new ProcessFormSubmissionEmail($emailJob))->handle();

        Mail::assertSent(FormSubmissionMail::class, 1);
        $emailJob->refresh();
        $this->assertSame(EmailJobStatus::Sent->value, $emailJob->status);
        $this->assertNotNull($emailJob->started_at);
        $this->assertNotNull($emailJob->completed_at);
        $this->assertSame(1, $emailJob->attempts);
    }

    public function test_job_marks_email_as_failed_when_mail_throws(): void
    {
        $form = Form::factory()->create();
        $submission = FormSubmission::factory()->for($form)->create();
        $emailJob = EmailJob::factory()->for($submission, 'submission')->create([
            'status' => EmailJobStatus::Pending->value,
        ]);

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new \RuntimeException('SMTP connection refused.'));

        try {
            (new ProcessFormSubmissionEmail($emailJob))->handle();
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException) {
            // expected
        }

        $emailJob->refresh();
        $this->assertSame(EmailJobStatus::Failed->value, $emailJob->status);
        $this->assertStringContainsString('SMTP', (string) $emailJob->error_message);
    }

    public function test_job_is_noop_when_email_already_sent(): void
    {
        Mail::fake();

        $form = Form::factory()->create();
        $submission = FormSubmission::factory()->for($form)->create();
        $emailJob = EmailJob::factory()->for($submission, 'submission')->sent()->create();

        (new ProcessFormSubmissionEmail($emailJob))->handle();

        Mail::assertNothingQueued();
        $emailJob->refresh();
        $this->assertSame(EmailJobStatus::Sent->value, $emailJob->status);
    }
}
