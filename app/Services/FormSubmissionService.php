<?php

namespace App\Services;

use App\Enums\EmailJobStatus;
use App\Enums\SubmissionStatus;
use App\Http\Resources\SubmissionResource;
use App\Jobs\ProcessFormSubmissionEmail;
use App\Models\EmailJob;
use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Centralises the validation, persistence, and queueing of a form submission.
 *
 * Used by both the public API controller and the in-app demo page so the
 * test path mirrors the real one exactly.
 */
class FormSubmissionService
{
    /**
     * Process an incoming submission and return a normalised result.
     *
     * @param  array<string, mixed>  $data
     * @return array{
     *     ok: bool,
     *     status: int,
     *     message: string,
     *     submission: array<string, mixed>|null,
     *     errors: array<string, array<int, string>>,
     *     fields: array<int, array<string, mixed>>,
     * }
     */
    public function submit(Form $form, array $data, Request $request): array
    {
        // Auto-discover fields on a field-less form, when enabled.
        if (! $form->hasActiveFields() && $form->auto_discover_fields) {
            app(FormFieldDiscoverer::class)->discover($form, $data);
            $form->refresh();
        }

        $validator = FormSubmissionValidator::make($form, $data);

        try {
            $validated = $validator->validate();
        } catch (ValidationException $exception) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => $exception->validator->errors()->first() ?: 'The given data was invalid.',
                'submission' => null,
                'errors' => $exception->errors(),
                'fields' => $form->activeFields()
                    ->map(fn ($field) => $field->toSchema())
                    ->values()
                    ->all(),
            ];
        }

        if ($form->is_archived) {
            return [
                'ok' => false,
                'status' => 410,
                'message' => 'This form is no longer accepting submissions.',
                'submission' => null,
                'errors' => [],
                'fields' => [],
            ];
        }

        if (! $form->send_email && ! $form->store_submissions) {
            return [
                'ok' => false,
                'status' => 410,
                'message' => 'This form is currently disabled.',
                'submission' => null,
                'errors' => [],
                'fields' => [],
            ];
        }

        $data = $validated['data'] ?? [];
        $ipAddress = $request->ip();
        $userAgent = $request->userAgent();
        $referer = $request->header('referer');

        try {
            [$submission, $emailJobs] = DB::transaction(function () use ($form, $data, $ipAddress, $userAgent, $referer): array {
                /** @var FormSubmission|null $submission */
                $submission = null;

                if ($form->store_submissions) {
                    $submission = FormSubmission::query()->create([
                        'form_id' => $form->id,
                        'submission_data' => $data,
                        'ip_address' => $ipAddress,
                        'user_agent' => $userAgent,
                        'referer' => $referer,
                        'status' => SubmissionStatus::Received->value,
                    ]);
                }

                /** @var array<int, EmailJob> $emailJobs */
                $emailJobs = [];

                if ($form->send_email && $submission) {
                    foreach ($form->recipient_emails as $recipient) {
                        $emailJobs[] = EmailJob::query()->create([
                            'submission_id' => $submission->id,
                            'status' => EmailJobStatus::Pending->value,
                            'recipient' => $recipient,
                            'subject' => $form->name,
                            'queued_at' => now(),
                        ]);
                    }
                }

                return [$submission, $emailJobs];
            });
        } catch (Throwable $exception) {
            Log::error('Failed to record form submission', [
                'form_id' => $form->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'status' => 500,
                'message' => 'Unable to process submission at this time.',
                'submission' => null,
                'errors' => [],
                'fields' => [],
            ];
        }

        foreach ($emailJobs as $emailJob) {
            ProcessFormSubmissionEmail::dispatch($emailJob);
        }

        return [
            'ok' => true,
            'status' => 201,
            'message' => $form->success_message,
            'submission' => $submission
                ? (new SubmissionResource($submission))->resolve($request)
                : null,
            'errors' => [],
            'fields' => [],
        ];
    }
}
