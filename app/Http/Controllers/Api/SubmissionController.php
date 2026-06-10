<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Services\FormSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function store(Request $request, Form $form): JsonResponse
    {
        if ($this->originIsForbidden($request, $form)) {
            return response()->json([
                'message' => 'Origin not allowed for this form.',
            ], 403);
        }

        $payload = $request->json()->all();
        $data = $payload['data'] ?? [];
        if (! is_array($data)) {
            $data = [];
        }

        try {
            $result = app(FormSubmissionService::class)->submit($form, $data, $request);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to process submission at this time.',
            ], 500);
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
