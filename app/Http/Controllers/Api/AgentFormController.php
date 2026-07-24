<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesSubmissionResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateAgent;
use App\Models\Form;
use App\Models\User;
use App\Services\Agent\EmbedSnippetGenerator;
use App\Services\Agent\FormHtmlParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agent-facing "create a form from an HTML snippet" endpoint.
 *
 * Authentication is via the **forms-agent** personal access token
 * (`forms_sk_…`, Sanctum-backed) — see {@see AuthenticateAgent}.
 * That key is high-privilege: it lets the caller create as many
 * forms as they want under the owning user's account. To keep the
 * blast radius of a leak small, the key is **creation-only**: it
 * never authenticates a submission.
 *
 * Submissions use the per-form `api_key` (returned in the response
 * payload alongside the embed snippet). That key is single-purpose,
 * scoped to one form, and is the only key that ships to the world
 * inside the snippet HTML.
 */
class AgentFormController extends Controller
{
    use HandlesSubmissionResponses;

    /**
     * Create a new form from an HTML snippet supplied by the agent.
     */
    public function store(
        Request $request,
        FormHtmlParser $parser,
        EmbedSnippetGenerator $snippets,
    ): Response {
        $data = $request->validate([
            'form_name' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9 _\-]+$/'],
            'html' => ['required', 'string', 'max:65535'],
            'description' => ['nullable', 'string', 'max:255'],
            'recipient_emails' => ['nullable', 'string', 'max:2000'],
            'from_email' => ['nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'success_redirect_url' => ['nullable', 'url', 'max:2000'],
            'success_message' => ['nullable', 'string', 'max:500'],
            'min_submission_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $slug = Str::slug($data['form_name']);

        // Per-user uniqueness. The composite unique index on (user_id, slug)
        // is the source of truth — this guard just gives a friendlier 409
        // response with a message the agent can act on.
        if (Form::query()->where('user_id', $user->id)->where('slug', $slug)->exists()) {
            return response()->json([
                'message' => "A form named '{$data['form_name']}' already exists for your account.",
                'form_name' => $data['form_name'],
            ], 409);
        }

        try {
            $parsed = $parser->parse($data['html']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $form = DB::transaction(function () use ($user, $data, $slug, $parsed): Form {
            $form = Form::query()->create([
                'user_id' => $user->id,
                'name' => $data['form_name'],
                'slug' => $slug,
                'description' => $data['description'] ?? null,
                'recipient_emails' => $this->parseRecipients($data['recipient_emails'] ?? null, $user),
                'from_email' => $data['from_email'] ?? null,
                'from_name' => $data['from_name'] ?? ($user->name ?? null),
                'success_message' => $data['success_message'] ?? null,
                'success_redirect_url' => $data['success_redirect_url'] ?? null,
                // The agent already discovered the fields, so do not
                // re-run auto-discovery on the first submission.
                'auto_discover_fields' => false,
                // The embed snippet targets the legacy per-form endpoint,
                // which uses the form's `api_key` (not the user-level
                // forms-agent key) for authentication.
                'endpoint' => '/api/forms/'.$slug,
                // The embed snippet is plain HTML — there's no
                // JavaScript to refresh a `_timestamp` field per page
                // load. Default to 0 (no timing check) so the snippet
                // is immediately usable. The agent can opt back into
                // timing protection by passing min_submission_seconds.
                'min_submission_seconds' => $data['min_submission_seconds'] ?? 0,
            ]);

            foreach ($parsed as $i => $row) {
                $form->fields()->create($row + ['position' => $i]);
            }

            return $form;
        });

        $form->load('fields');

        $payload = [
            // Public submission URL. Visitors hit the legacy
            // `/api/forms/{slug}` endpoint with the per-form api_key
            // in a hidden body field (no query string).
            'form_url' => url('/api/forms/'.$form->slug),
            'slug' => $form->slug,
            'name' => $form->name,
            // Per-form api_key. The agent embeds this in the snippet
            // and ships the snippet to the user's static site. It is
            // scoped to this one form and never grants any other
            // capability.
            'api_key' => $form->api_key,
            'fields' => $form->activeFields()->map->toSchema()->values()->all(),
            'embed_html' => $snippets->build($form, $form->api_key),
        ];

        if ($this->wantsHtmlResponse($request)) {
            return response()->view('agent.form-created', [
                'form' => $form,
                'payload' => $payload,
            ]);
        }

        return response()->json($payload, 201);
    }

    /**
     * Parse a comma- or semicolon-separated recipient email list. Falls
     * back to the user's own email when none is supplied so the form
     * has at least one destination out of the box.
     *
     * @return array<int, string>
     */
    protected function parseRecipients(?string $csv, User $user): array
    {
        if ($csv === null || trim($csv) === '') {
            return [$user->email];
        }

        $parts = preg_split('/[,;]\s*/', $csv) ?: [];

        return collect($parts)
            ->map(fn (string $part): string => trim($part))
            ->filter(fn (string $part): bool => $part !== '' && filter_var($part, FILTER_VALIDATE_EMAIL) !== false)
            ->values()
            ->all();
    }
}
