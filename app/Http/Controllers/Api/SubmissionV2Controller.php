<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesSubmissionResponses;
use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\User;
use App\Services\FormSubmissionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * User-key-authenticated submission endpoint for forms created via the
 * `/api/agent/forms` workflow.
 *
 * The `agent.key` middleware resolves the calling user from the forms
 * key they carried (Authorization header, query string, or `_user_api`
 * body field). This controller then verifies that the user owns the
 * target form and delegates the actual submission pipeline to
 * `FormSubmissionService` — the same pipeline the legacy
 * `SubmissionController` uses, so spam protection, validation, and
 * email delivery behave identically.
 */
class SubmissionV2Controller extends Controller
{
    use HandlesSubmissionResponses;

    /**
     * Process a new submission against the supplied form.
     */
    public function store(Request $request, Form $form): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($form->user_id !== $user->id) {
            return response()->json([
                'message' => 'Form key does not match form owner.',
            ], 403);
        }

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

        if ($this->wantsHtmlResponse($request) && $result['ok'] && $result['redirect_url'] !== null) {
            return redirect()->to($result['redirect_url']);
        }

        if ($this->wantsHtmlResponse($request) && ! $result['ok'] && $redirectUrl !== null) {
            return $this->redirectWithError($redirectUrl, $result['status'], $result['errors']);
        }

        $body = ['message' => $result['message']];

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
}
