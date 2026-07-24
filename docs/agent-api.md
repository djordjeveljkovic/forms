# Forms-app agent API

The agent API lets an external AI agent (Claude, Cursor, etc.) create
HTML forms on behalf of a signed-in user. The workflow:

1. The user signs in and generates a **forms-agent** personal access
   token from the dashboard at `/dashboard/agent-key`.
2. The user hands that token to the AI agent.
3. The agent POSTs an HTML snippet to `POST /api/agent/forms`,
   authenticated with the forms-agent token.
4. The agent receives a `form_url`, a per-form `api_key`, and a
   copy-pasteable HTML embed snippet that embeds the per-form key.
5. The agent drops the snippet on the user's static site.
6. Visitors who submit the embed snippet create `form_submissions`
   rows in the user's dashboard and trigger the existing email
   pipeline.

## Two keys, two roles

| Key | Format | Capability | Where it lives |
|---|---|---|---|
| forms-agent key | `forms_sk_…` (Sanctum token, name `forms-agent`) | Create new forms on the user's behalf | Only in the agent's hands — **never** in the snippet HTML |
| Per-form api_key | 48-char random string | Submit to one specific form | In the snippet as a hidden body field, and in the agent's response payload |

The forms-agent key is **creation-only**. It cannot be used to submit
to any form, and the per-form `api_key` cannot be used to create
new forms. This is a security split:

- The agent holds a high-privilege key (can spin up as many forms as
  it wants under the user's account) but only ever transports it
  over HTTPS as an Authorization header. It never gets embedded in
  HTML that ships to the world.
- The HTML snippet holds a low-privilege key (scoped to one form)
  but it does ship to the world. If it leaks, the worst case is
  spam against that one form, which the existing rate limiter and
  spam-protection service already handle.

Both keys are required to be authenticated with the **name** field on
the Sanctum token — a `forms_sk_…` token is rejected when presented
to `/api/forms/{slug}` (returns `401`), and the per-form `api_key`
is rejected when presented to `/api/agent/forms` (returns `401`).

## Endpoints

### `POST /api/agent/forms`

Create a form from an HTML snippet. Auth: forms-agent key.

| Parameter | Required | Type | Notes |
|---|---|---|---|
| `form_name` | yes | string, 1–80 chars | matches `^[a-zA-Z0-9 _\-]+$`; slugified for the URL. |
| `html` | yes | string, ≤ 65 KB | the raw HTML snippet. |
| `description` | no | string | shown on the dashboard form list. |
| `recipient_emails` | no | string | comma- or semicolon-separated; defaults to the user's email. |
| `from_email` | no | email | sender for notification emails. |
| `from_name` | no | string | sender display name; defaults to the user's name. |
| `success_redirect_url` | no | URL | where browser visitors are sent after submitting. |
| `success_message` | no | string | shown to JSON clients on success. |
| `min_submission_seconds` | no | int 0–3600 | default `0` — no timing check. |

Returns `201 Created` for agents, `200 OK` HTML success page for
browsers. JSON shape:

```json
{
  "form_url": "https://forms-app.example.com/api/forms/contact",
  "slug": "contact",
  "name": "contact",
  "api_key": "KFS_per_form_secret_…",
  "fields": [
    {"name": "email", "label": "Email", "type": "email", "required": true, …}
  ],
  "embed_html": "<form action=\"…\" method=\"POST\">…</form>"
}
```

Error responses:

- `401 Unauthorized` — missing or invalid forms-agent key.
- `409 Conflict` — a form with the same name already exists for this
  user.
- `422 Unprocessable Entity` — invalid parameters or no usable fields
  in the snippet.

Example:

```bash
curl -X POST https://forms-app.example.com/api/agent/forms \
  -H "Authorization: Bearer forms_sk_…" \
  -F 'form_name=contact' \
  -F 'html=<form method="POST"><input type="email" name="email" required></form>'
```

### `POST /api/forms/{slug}` (visitor submissions)

The legacy endpoint, reused. Authentication is via the per-form
`api_key` in any of four forms (priority order):

1. `X-Form-Key: …` header.
2. `X-Api-Key: …` header.
3. `?api_key=…` query string.
4. `api_key=…` POST body field — what the embed snippet uses, since
   plain HTML forms can only send body fields and query strings.

The embed snippet posts to `/api/forms/{slug}` (no query string) with
the per-form `api_key` as a hidden body field. The key does not
appear in the URL, so it does not leak into browser history, server
access logs, or `Referer` headers on the user's success page.

Returns:

- `201 Created` JSON for agents (with `{message, submission}`).
- `302 Found` redirect to `success_redirect_url` for browsers.
- `401 Unauthorized` — missing or invalid api_key, **or** the caller
  presented the forms-agent key (which is creation-only).
- `422 Unprocessable Entity` with `errors` map on validation failure.
- `429 Too Many Requests` on rate limit (per-form, per-IP, default
  60/hour).

## AI-discoverable docs

Two endpoints serve the same Markdown body, cached for 1 hour:

- `GET /api/llms.txt` — `Content-Type: text/markdown; charset=utf-8`.
- `GET /api/agent/docs` — same content as JSON
  (`{"content": "...", "format": "markdown"}`).

Both list every endpoint, the auth scheme, example requests, and
example responses. Edit `AgentDocsController::buildMarkdown()` to
change the docs and bump the cache key constant
(`AgentDocsController::CACHE_KEY`) to invalidate.

## Security notes

- Treat the forms-agent key as a password. Anyone holding it can
  create forms under the user's account.
- Revoke the forms-agent key from `/dashboard/agent-key` if it leaks.
  Revoking immediately invalidates the key for all in-flight agents.
- The per-form `api_key` is lower-privilege (single-form submissions).
  If it leaks, attackers can spam that one form. The existing rate
  limiter caps this at 60 requests/hour per IP.
- The forms-agent key never appears in the snippet HTML. The per-form
  key never appears in a URL or header that the user controls.
- The HTML snippet is parsed with DOMDocument (with `LIBXML_NONET`
  to block external DTDs). Honeypot fields (anything inside an
  off-screen `<div>`) are stripped. Control fields prefixed with `_`
  are stripped, and `api_key` itself is stripped before submission
  validation runs.
- Audit log entries are written for every forms-agent key
  generate/revoke event (`forms-agent.key.generated`,
  `forms-agent.key.revoked`) with the user's IP.

## Dashboard

`/dashboard/agent-key` shows:

- Token status: "Active" with last-4 fingerprint, created-at,
  last-used-at, or "No key" with a Generate button.
- A one-time-reveal modal that shows the plaintext forms-agent key
  once with a copy button. After the modal closes, the plaintext is
  gone.
- A revoke confirmation modal.

A sidebar item "Forms agent API" appears in the `Forms` group on the
dashboard layout.