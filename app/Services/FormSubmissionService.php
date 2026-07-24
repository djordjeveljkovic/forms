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
     *     redirect_url: string|null,
     * }
     */
    public function submit(Form $form, array $data, Request $request, ?string $redirectUrl = null): array
    {
        // Strip control fields (anything starting with `_` plus the
        // Turnstile token) so they never reach the validator or get
        // persisted into the submission JSON. The honeypot field is
        // stripped later so the spam-protection check has a chance to
        // read it from the request.
        $data = $this->stripControlFields($data);

        // Auto-discover fields on a field-less form, when enabled.
        // Auto-discovery runs *before* spam protection so the form
        // owner can see the first submission as a configured form
        // even when the bot-protection rules block it.
        if (! $form->hasActiveFields() && $form->auto_discover_fields && $data !== []) {
            app(FormFieldDiscoverer::class)->discover($form, $data);
            $form->refresh();
        }

        // Run spam protection before field validation so spam never
        // generates per-field validation errors that would leak form
        // structure to bots.
        $spam = app(FormSpamProtectionService::class)->verify($form, $request, $data);
        if (! $spam->passed) {
            return [
                'ok' => false,
                'status' => $spam->status,
                'message' => 'Submission could not be processed.',
                'submission' => null,
                'errors' => [],
                'fields' => $form->activeFields()
                    ->map(fn ($field) => $field->toSchema())
                    ->values()
                    ->all(),
                'redirect_url' => $redirectUrl,
            ];
        }

        // Strip the honeypot field now that the spam check has read
        // it — it would otherwise trip the validator's unknown-field
        // rejection and pollute the submission JSON.
        $data = $this->stripHoneypotField($form, $data);

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
                'redirect_url' => $redirectUrl,
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
                'redirect_url' => $redirectUrl,
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
                'redirect_url' => $redirectUrl,
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

                // We always persist a submission row when we need one for
                // downstream features (e.g. email jobs have a FK to
                // form_submissions). Forms that explicitly opt out of
                // storage but still want email notifications would
                // otherwise silently drop every email. The
                // `store_submissions` flag is honoured by the dashboard
                // (it filters these rows out of the listing) but the
                // data is still kept so emails and audit trails work.
                if ($form->store_submissions || $form->send_email) {
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
                            // Persist the resolved subject so the email-jobs
                            // dashboard shows what recipients will actually
                            // see, not the raw form name.
                            'subject' => $this->resolveSubject($form),
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
                'redirect_url' => $redirectUrl,
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
            'redirect_url' => $this->buildRedirectUrl($redirectUrl, $submission),
        ];
    }

    /**
     * Strip internal control fields (leading underscore + the Turnstile
     * response token + the per-form api_key) from a payload so they
     * never reach the validator or get persisted into the submission
     * JSON. The `api_key` field is read by `VerifyFormApiKey` to
     * authenticate the request and must be removed before validation.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function stripControlFields(array $data): array
    {
        return collect($data)
            ->reject(fn (mixed $value, string $key) => str_starts_with($key, '_')
                || $key === 'cf-turnstile-response'
                || $key === 'api_key')
            ->all();
    }

    /**
     * Strip the form's honeypot field from the payload. Called after
     * the spam check has read the value, so it never reaches the
     * validator.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function stripHoneypotField(Form $form, array $data): array
    {
        $honeypot = (string) ($form->honeypot_field ?: 'website');

        return collect($data)
            ->reject(fn (mixed $value, string $key) => $key === $honeypot)
            ->all();
    }

    /**
     * Build the final redirect URL by appending the submission id and
     * status as query parameters so the landing page can show the right
     * state. Only used when the caller actually wants to redirect.
     */
    protected function buildRedirectUrl(?string $base, ?FormSubmission $submission): ?string
    {
        if ($base === null || $base === '') {
            return null;
        }

        $params = [];
        if ($submission) {
            $params['submission_id'] = $submission->id;
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return $params === [] ? $base : $base.$separator.http_build_query($params);
    }

    /**
     * Resolve the email subject using the form's subject template.
     */
    protected function resolveSubject(Form $form): string
    {
        $template = $form->subject_template ?: 'New submission for :form_name';

        return (string) strtr($template, [
            ':form_name' => $form->name,
            ':form_slug' => $form->slug,
        ]);
    }
}
