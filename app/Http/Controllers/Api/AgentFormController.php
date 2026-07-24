<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesSubmissionResponses;
use App\Http\Controllers\Controller;
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
 * An AI agent POSTs a raw HTML snippet plus a desired form name. The
 * server parses the snippet into field definitions, persists a new
 * Form under the calling user, and returns the public submission URL
 * along with a copy-pasteable HTML embed snippet the agent can hand
 * to the user.
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
                // Override the legacy /api/forms/{slug} default with
                // the agent-facing submission URL.
                'endpoint' => '/api/submit/'.$slug,
            ]);

            foreach ($parsed as $i => $row) {
                $form->fields()->create($row + ['position' => $i]);
            }

            return $form;
        });

        $form->load('fields');

        $payload = [
            'form_url' => url('/api/submit/'.$form->slug),
            'slug' => $form->slug,
            'name' => $form->name,
            'fields' => $form->activeFields()->map->toSchema()->values()->all(),
            'embed_html' => $snippets->build($form, $this->currentKeyForSnippet($request, $user)),
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

    /**
     * Decide which user-key value to bake into the embed snippet.
     *
     * - Agents that authenticated with the key get a working snippet
     *   that posts straight back to the new form.
     * - Browsers hitting the endpoint with `Accept: text/html` never
     *   typed a key in (the page renders the success view in response
     *   to a form submit that included `_user_api`); we substitute a
     *   placeholder so we don't expose a plaintext key in the HTML
     *   they receive.
     */
    protected function currentKeyForSnippet(Request $request, User $user): string
    {
        if ($request->header('Authorization') || $request->query('user_api')) {
            return $this->extractSentKey($request);
        }

        return '__YOUR_FORMS_KEY__';
    }

    /**
     * Re-read the key the caller used to authenticate. We only know
     * the plaintext key from the request, never from the database
     * (Sanctum hashes them on insert).
     */
    protected function extractSentKey(Request $request): string
    {
        $authorization = $request->header('Authorization');
        if (is_string($authorization) && str_starts_with(strtolower($authorization), 'bearer ')) {
            return trim(substr($authorization, 7));
        }

        $query = $request->query('user_api');
        if (is_string($query) && $query !== '') {
            return $query;
        }

        $body = $request->input('_user_api');
        if (is_string($body) && $body !== '') {
            return $body;
        }

        return '__YOUR_FORMS_KEY__';
    }
}
