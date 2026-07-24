<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Serves the AI-discoverable API documentation.
 *
 * - `GET /llms.txt` — plain Markdown (the de-facto standard for AI
 *   tooling; many clients fetch this automatically).
 * - `GET /api/agent/docs` — same content wrapped as JSON for tools
 *   that prefer structured responses.
 *
 * The Markdown body is built once per hour and cached. To regenerate
 * after editing the docs template, run `php artisan cache:clear` or
 * wait for the TTL to expire.
 */
class AgentDocsController extends Controller
{
    /**
     * The cache key used by `Cache::remember` for the Markdown body.
     * Bumping the suffix forces a refresh after content edits.
     */
    private const CACHE_KEY = 'agent-llms:v1';

    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Return the agent API documentation as Markdown.
     */
    public function llms(Request $request): Response
    {
        $body = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn (): string => $this->buildMarkdown());

        return response($body, 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Return the same documentation as JSON.
     */
    public function docs(Request $request): JsonResponse
    {
        return response()->json([
            'content' => Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn (): string => $this->buildMarkdown()),
            'format' => 'markdown',
        ]);
    }

    /**
     * Build the Markdown documentation body.
     *
     * Kept as a method (rather than a static string) so future edits
     * stay in the source-of-truth controller and the cache invalidates
     * when the constant changes.
     */
    protected function buildMarkdown(): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return <<<MARKDOWN
        # forms-app agent API

        forms-app is a Laravel 13 + Livewire service that lets signed-in users create HTML forms
        and have visitors submit them. This document describes the **agent-facing** surface that
        lets an AI agent (Claude, Cursor, etc.) create forms on a user's behalf when the user
        hands over their **forms-agent** personal access token.

        ## Conventions

        - All responses are JSON unless the request sends `Accept: text/html`.
        - Browser form posts return a 302 redirect to `success_redirect_url` (or the inline
          success view if none is configured).
        - The agent response never contains the per-form `api_key` (only the user's own key
          is exposed via `embed_html`).
        - HTML snippets passed to the create endpoint are parsed with DOMDocument and are
          scanned for honeypot fields. Anything inside an off-screen `<div>` is dropped.

        ## Authentication

        Every write endpoint accepts the user's forms-agent token (a Sanctum personal access
        token whose name is `forms-agent`) in one of three forms:

        1. `Authorization: Bearer forms_sk_…` — preferred, never written to logs.
        2. `?user_api=forms_sk_…` — needed for plain HTML form posts that can't set headers.
        3. `_user_api=forms_sk_…` body field — fallback for HTML form posts.

        The user generates their key once from the dashboard at `/dashboard/agent-key`. Any
        other token (including the legacy per-form `api_key`) is rejected with `401`.

        ## Endpoints

        ### GET /llms.txt

        Returns this document as Markdown. Cache TTL: 1 hour.

        ### GET /api/agent/docs

        Returns the same content as JSON: `{"content": "...markdown...", "format": "markdown"}`.

        ### POST /api/agent/forms

        Create a new form from an HTML snippet.

        **Auth:** required (any of the three forms above).

        **Content-Type:** `multipart/form-data`.

        **Body parameters:**

        | name | required | type | notes |
        |---|---|---|---|
        | `form_name` | yes | string, 1–80 chars | matches `^[a-zA-Z0-9 _\-]+$`; slugified for the URL. |
        | `html` | yes | string, ≤ 65 KB | the raw HTML snippet the form will be built from. |
        | `description` | no | string | shown on the dashboard form list. |
        | `recipient_emails` | no | string | comma- or semicolon-separated; defaults to the user's own email. |
        | `from_email` | no | email | sender for notification emails. |
        | `from_name` | no | string | sender display name; defaults to the user's name. |
        | `success_redirect_url` | no | URL | where browser visitors are sent after submitting. |
        | `success_message` | no | string | shown to JSON clients on success. |

        **Success response (agent, JSON):** `201 Created`

        ```json
        {
          "form_url": "{$base}/api/submit/contact",
          "slug": "contact",
          "name": "contact",
          "fields": [
            {"name": "email", "label": "Email", "type": "email", "required": true}
          ],
          "embed_html": "<form action=\\"{$base}/api/submit/contact\\" method=\\"POST\\">…</form>"
        }
        ```

        **Success response (browser, HTML):** `200 OK` with an inline Flux page that has copy
        buttons for `form_url` and `embed_html`.

        **Error responses:**

        - `401 Unauthorized` — missing or invalid forms key.
        - `409 Conflict` — a form with the same name already exists for this user.
        - `422 Unprocessable Entity` — invalid parameters or no usable fields in the snippet.

        **Example:**

        ```bash
        curl -X POST $base/api/agent/forms \\
          -H "Authorization: Bearer forms_sk_..." \\
          -F 'form_name=contact' \\
          -F 'html=<form action="/x" method="POST"><label>Email<input type="email" name="email" required></label></form>'
        ```

        ### POST /api/submit/{slug}

        Submit visitor data against a form created via the agent endpoint.

        **Auth:** required — same user-key, plus either `?user_api=…` query or a hidden
        `_user_api` body field (the embed snippet includes the latter automatically).

        **Body:** form-encoded submission values matching the form's configured fields. Any
        extra fields prefixed with `_` are treated as control fields and are stripped before
        validation. The honeypot field (default name `website`) is checked by the spam
        protection service and then stripped.

        **Success response (agent, JSON):** `201 Created` with `{message, submission}`.

        **Success response (browser):** `302 Found` to `success_redirect_url` if configured,
        otherwise `200 OK` JSON.

        **Error responses:**

        - `401 Unauthorized` — missing or invalid forms key.
        - `403 Forbidden` — the supplied key does not own the target form.
        - `422 Unprocessable Entity` — validation failed; `errors` map field names to messages.
        - `429 Too Many Requests` — rate limited (per-form, per-IP, default 60/hour).

        ## Security notes

        - Treat the forms-agent key as a password. Anyone holding it can create forms under
          the user's account.
        - Revoke the key from `/dashboard/agent-key` if it leaks. Revoking immediately
          invalidates the key for all in-flight agents.
        - Visitors submitting the embed snippet never see the key — the snippet carries it
          in a hidden `_user_api` input, not the URL.
        MARKDOWN;
    }
}
