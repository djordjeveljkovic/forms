<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesSubmissionResponses;
use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Services\FormSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SubmissionController extends Controller
{
    use HandlesSubmissionResponses;

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

        if ($this->overMonthlySubmissionLimit($form)) {
            return response()->json([
                'message' => 'Form owner has reached their plan submission limit for this month.',
            ], 429);
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
}
