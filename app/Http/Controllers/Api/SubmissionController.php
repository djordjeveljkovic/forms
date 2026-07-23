<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Services\FormSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SubmissionController extends Controller
{
    /**
     * Return the form's metadata and configured field schema.
     */
    public function show(Request $request, Form $form): JsonResponse
    {
        return response()->json([
            'form' => [
                'name' => $form->name,
                'slug' => $form->slug,
                'description' => $form->description,
                'success_message' => $form->success_message,
                'subject_template' => $form->subject_template,
                'submitter_reply_to_field' => $form->submitter_reply_to_field,
                'auto_discover_fields' => (bool) $form->auto_discover_fields,
            ],
            'fields' => $form->activeFields()
                ->map(fn ($field) => $field->toSchema())
                ->values()
                ->all(),
        ]);
    }

    /**
     * Store a new submission for the given form and dispatch email jobs.
     */
    public function store(Request $request, Form $form): Response
    {
        if ($this->originIsForbidden($request, $form)) {
            return response()->json([
                'message' => 'Origin not allowed for this form.',
            ], 403);
        }

        $data = $this->extractSubmissionData($request);
        $redirectUrl = $this->resolveRedirectUrl($request, $form);

        try {
            $result = app(FormSubmissionService::class)->submit($form, $data, $request, $redirectUrl);
        } catch (Throwable $exception) {
            report($exception);

            if ($this->wantsHtmlResponse($request) && $redirectUrl !== null) {
                return $this->redirectWithError($redirectUrl, 500);
            }

            return response()->json([
                'message' => 'Unable to process submission at this time.',
            ], 500);
        }

        // Browser-driven submissions (plain HTML form posts) get a
        // 302 redirect to the form owner's site so the user lands on
        // their thank-you page. Programmatic clients (fetch with
        // Accept: application/json) get the structured JSON response.
        if ($this->wantsHtmlResponse($request) && $result['ok'] && $result['redirect_url'] !== null) {
            return redirect()->to($result['redirect_url']);
        }

        if ($this->wantsHtmlResponse($request) && ! $result['ok'] && $redirectUrl !== null) {
            return $this->redirectWithError($redirectUrl, $result['status'], $result['errors']);
        }

        $body = [
            'message' => $result['message'],
        ];

        if ($result['ok']) {
            $body['submission'] = $result['submission'];
        } else {
            $body['errors'] = $result['errors'];
            if ($result['fields'] !== []) {
                $body['fields'] = $result['fields'];
            }
        }

        return response()->json($body, $result['status']);
    }

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
}
