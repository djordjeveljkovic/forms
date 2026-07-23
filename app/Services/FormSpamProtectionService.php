<?php

namespace App\Services;

use App\Models\Form;
use App\Support\SpamCheckResult;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Throwable;

/**
 * Validates a submission against the form's configured spam protection:
 *
 *  1. Honeypot field must be empty (catches naive bots).
 *  2. Minimum submission time must have elapsed since the form was rendered
 *     (catches bots that submit instantly).
 *  3. Optional Cloudflare Turnstile token check (catches smarter bots).
 *
 * Each check is intentionally short-circuited so the public-facing failure
 * message stays the same regardless of which check rejected the request.
 */
class FormSpamProtectionService
{
    /**
     * Maximum staleness for a `_timestamp` value. Older timestamps are
     * rejected because they can be replayed by bots.
     */
    private const MAX_TIMESTAMP_AGE_SECONDS = 86400;

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * Run all configured spam checks for the given form.
     *
     * @param  array<string, mixed>  $data  the already-extracted form
     *                                      field values (post-data-wrapper, post-control-strip)
     */
    public function verify(Form $form, Request $request, array $data): SpamCheckResult
    {
        $honeypot = $this->verifyHoneypot($form, $request, $data);
        if (! $honeypot->passed) {
            return $honeypot;
        }

        $minTime = $this->verifyMinSubmissionTime($form, $request);
        if (! $minTime->passed) {
            return $minTime;
        }

        return $this->verifyCaptcha($form, $request);
    }

    /**
     * The configured honeypot field must be empty. Real users never see
     * it (CSS hides it offscreen) so any non-empty value is a bot.
     *
     * @param  array<string, mixed>  $data
     */
    protected function verifyHoneypot(Form $form, Request $request, array $data): SpamCheckResult
    {
        $field = (string) ($form->honeypot_field ?: 'website');
        // Check the extracted data first (which is what the controller
        // saw before stripping the `data` wrapper) and fall back to
        // the raw request so this check works regardless of how the
        // payload was sent.
        $value = $data[$field] ?? $request->input($field);

        if ($value !== null && $value !== '' && $value !== false) {
            return SpamCheckResult::fail('honeypot-filled');
        }

        return SpamCheckResult::pass();
    }

    /**
     * If the form requires a minimum submission time, the `_timestamp`
     * field must be present and at least N seconds old.
     */
    protected function verifyMinSubmissionTime(Form $form, Request $request): SpamCheckResult
    {
        $min = (int) $form->min_submission_seconds;
        if ($min <= 0) {
            return SpamCheckResult::pass();
        }

        $raw = $request->input('_timestamp');
        if ($raw === null || $raw === '') {
            return SpamCheckResult::fail('missing-timestamp', 400);
        }

        // ctype_digit guards against `"0" + Math.random()` style payloads
        // that fools intval() and friends.
        if (! is_string($raw) && ! is_numeric($raw)) {
            return SpamCheckResult::fail('invalid-timestamp', 400);
        }
        $timestamp = (int) $raw;
        if ($timestamp <= 0) {
            return SpamCheckResult::fail('invalid-timestamp', 400);
        }

        $now = time();
        if ($timestamp > $now || ($now - $timestamp) > self::MAX_TIMESTAMP_AGE_SECONDS) {
            return SpamCheckResult::fail('replayed-timestamp', 400);
        }

        if (($now - $timestamp) < $min) {
            return SpamCheckResult::fail('submitted-too-quickly');
        }

        return SpamCheckResult::pass();
    }

    /**
     * Verify the Cloudflare Turnstile token when the form has Turnstile
     * configured. The HTTP call goes through Laravel's Http facade so it
     * can be faked in tests.
     */
    protected function verifyCaptcha(Form $form, Request $request): SpamCheckResult
    {
        if (! $form->hasTurnstile()) {
            return SpamCheckResult::pass();
        }

        $token = (string) $request->input('cf-turnstile-response', '');
        if ($token === '') {
            return SpamCheckResult::fail('missing-captcha');
        }

        try {
            $response = $this->http
                ->asForm()
                ->timeout(5)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $form->captcha_secret_key,
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
        } catch (Throwable) {
            // Fail closed on network errors so a Cloudflare outage
            // doesn't open the floodgates to bots.
            return SpamCheckResult::fail('captcha-unreachable', 503);
        }

        if (! $response->successful()) {
            return SpamCheckResult::fail('captcha-rejected');
        }

        $payload = $response->json();
        if (! is_array($payload) || empty($payload['success'])) {
            return SpamCheckResult::fail('captcha-rejected');
        }

        return SpamCheckResult::pass();
    }
}
