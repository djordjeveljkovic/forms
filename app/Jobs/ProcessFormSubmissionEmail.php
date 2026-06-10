<?php

namespace App\Jobs;

use App\Enums\EmailJobStatus;
use App\Mail\FormSubmissionMail;
use App\Models\EmailJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ProcessFormSubmissionEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of exceptions handled per attempt.
     */
    public int $maxExceptions = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 30;

    /**
     * Indicate if the job should be marked as failed on timeout.
     */
    public bool $failOnTimeout = true;

    /**
     * Create a new job instance.
     */
    public function __construct(public EmailJob $emailJob)
    {
        //
    }

    /**
     * Backoff in seconds between retries.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $emailJob = $this->emailJob->fresh();

        if (! $emailJob) {
            return;
        }

        if ($emailJob->status === EmailJobStatus::Sent->value) {
            return;
        }

        $emailJob->markProcessing();

        $submission = $emailJob->submission;

        if (! $submission) {
            $emailJob->markFailed('Submission no longer exists.');

            return;
        }

        $form = $submission->form;

        if (! $form) {
            $emailJob->markFailed('Form no longer exists.');

            return;
        }

        try {
            Mail::to($emailJob->recipient)
                ->send(new FormSubmissionMail($form, $submission));

            $emailJob->markSent();
        } catch (Throwable $exception) {
            $emailJob->markFailed($exception->getMessage());

            Log::warning('Form submission email failed', [
                'email_job_id' => $emailJob->id,
                'recipient' => $emailJob->recipient,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $current = $this->emailJob->fresh();

        if ($current && $current->status !== EmailJobStatus::Failed->value) {
            $current->markFailed(
                $exception?->getMessage() ?? 'Unknown error during email delivery.'
            );
        }
    }
}
