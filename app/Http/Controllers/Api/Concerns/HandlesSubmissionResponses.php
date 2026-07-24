<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Form;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared response shaping for the two public submission endpoints.
 *
 * Both `SubmissionController` (legacy per-form api_key) and
 * `SubmissionV2Controller` (agent user-key) need to produce identical
 * behaviour for browser form posts and programmatic clients:
 *
 * - Extract submission data from JSON / form-encoded bodies.
 * - Resolve a redirect URL from `?return_url`, the `_redirect` field,
 *   or `form.success_redirect_url`.
 * - Decide whether the caller wants an HTML response (and therefore a
 *   302 redirect on success/failure) or JSON.
 * - Build error redirects that carry the validation messages back to
 *   the landing page.
 * - Enforce `form.allowed_origins` CORS rules.
 *
 * Pulling these into a single trait guarantees both endpoints behave
 * the same way. If a behaviour change is needed, it only has to be
 * made in one place.
 */
trait HandlesSubmissionResponses
{
    /**
     * Pull the form field values out of the request, regardless of
     * whether the client sent JSON (with or without a `data` wrapper)
     * or a form-encoded body.
     *
     * @return array<string, mixed>
     */
    protected function extractSubmissionData(Request $request): array
    {
        $contentType = (string) $request->header('content-type', '');
        /** @var array<string, mixed> $raw */
        $raw = str_contains($contentType, 'application/json')
            ? (array) $request->json()->all()
            : (array) $request->post();

        // JSON clients may wrap the payload in `{ "data": { ... } }`
        // for compatibility with the existing API. If that key is
        // present, use it as the submission payload. Otherwise treat
        // the whole body as the payload.
        if (array_key_exists('data', $raw) && is_array($raw['data'])) {
            /** @var array<string, mixed> $data */
            $data = $raw['data'];

            return $data;
        }

        return $raw;
    }

    /**
     * Resolve the redirect URL for after-success navigation, in
     * priority order:
     *   1. `return_url` query parameter
     *   2. `_redirect` field on the form payload
     *   3. `form.success_redirect_url` configured on the form
     */
    protected function resolveRedirectUrl(Request $request, Form $form): ?string
    {
        $queryRedirect = $request->query('return_url');
        if (is_string($queryRedirect) && $queryRedirect !== '') {
            return $queryRedirect;
        }

        $fieldRedirect = $request->input('_redirect');
        if (is_string($fieldRedirect) && $fieldRedirect !== '') {
            return $fieldRedirect;
        }

        if (is_string($form->success_redirect_url) && $form->success_redirect_url !== '') {
            return $form->success_redirect_url;
        }

        return null;
    }

    /**
     * Determine whether the client asked for an HTML response (and
     * therefore wants a redirect on success/failure).
     */
    protected function wantsHtmlResponse(Request $request): bool
    {
        if ($request->header('X-Form-Key')) {
            return false;
        }

        $accept = (string) $request->header('accept', '');
        if ($accept === '') {
            return true;
        }

        // "*/*" is what curl / browsers send — treat as HTML unless
        // the client explicitly asks for JSON.
        if (str_contains($accept, 'application/json') && ! str_contains($accept, 'text/html')) {
            return false;
        }

        return str_contains($accept, 'text/html') || str_contains($accept, '*/*');
    }

    /**
     * Build a redirect back to the form owner's site carrying any
     * validation errors as query parameters. The landing page can
     * read these to render the error inline.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    protected function redirectWithError(string $url, int $status, array $errors = []): Response
    {
        $params = ['status' => $status === 422 ? 'invalid' : 'error'];
        if ($errors !== []) {
            $params['errors'] = json_encode($errors, JSON_THROW_ON_ERROR);
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return redirect()->to($url.$separator.http_build_query($params));
    }

    /**
     * Determine whether the request origin is allowed.
     */
    protected function originIsForbidden(Request $request, Form $form): bool
    {
        $allowed = (array) ($form->allowed_origins ?? []);

        if (count($allowed) === 0) {
            return false;
        }

        $origin = $request->header('origin') ?: $request->header('referer');

        if (! $origin) {
            return false;
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        $merged = collect($allowed)
            ->merge((array) config('forms.global_allowed_origins', []))
            ->filter()
            ->map(fn (string $entry) => parse_url($entry, PHP_URL_HOST) ?: $entry)
            ->all();

        return $originHost && ! in_array($originHost, $merged, true);
    }

    /**
     * Determine whether the form's owner has reached their plan's
     * monthly submission limit. Admins always pass.
     */
    protected function overMonthlySubmissionLimit(Form $form): bool
    {
        $owner = $form->user;

        if (! $owner instanceof User) {
            return false;
        }

        return $owner->hasReachedMonthlySubmissionLimit();
    }
}
