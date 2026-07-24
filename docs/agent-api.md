# Forms-app agent API

The agent API lets an external AI agent (Claude, Cursor, etc.) create
and submit HTML forms on behalf of a signed-in user. The workflow:

1. The user signs in and generates a **forms-agent** personal access
   token from the dashboard at `/dashboard/agent-key`.
2. The user hands that token to the AI agent.
3. The agent POSTs an HTML snippet to `POST /api/agent/forms`.
4. The agent receives a copy-pasteable HTML embed snippet back and
   drops it on the user's static site.
5. Visitors who submit the embed snippet create `form_submissions`
   rows in the user's dashboard and trigger the existing email
   pipeline.

## Why a per-user key (not the per-form `api_key`)?

Forms created through the dashboard already have their own
`api_key`. The per-user forms-agent token is a higher-trust capability
— it lets an agent create *new* forms on the user's behalf, not just
submit to existing ones. Anyone holding the per-user token can spin
up as many forms as they want under that user's account, so it must
be treated like a password.

The per-form `api_key` continues to work for `POST /api/forms/{slug}`
unchanged. The new endpoints use the user-key scheme:

- `POST /api/agent/forms` — agent creates a form.
- `POST /api/submit/{slug}` — visitors submit to that form.
- `GET /llms.txt`, `GET /api/agent/docs` — AI-discoverable docs.

## Authentication

The agent user-key is sent in one of three forms (in priority order):

1. `Authorization: Bearer forms_sk_…` — preferred.
2. `?user_api=forms_sk_…` query string — for HTML form posts that
   can't set headers.
3. `_user_api=forms_sk_…` body field — fallback for HTML form posts.

The token MUST be a Sanctum personal access token whose name is
`forms-agent`. Any other token (including the legacy per-form key)
is rejected with `401 Invalid or missing forms key.`.

## Endpoints

### `POST /api/agent/forms`

Create a form from an HTML snippet. Accepts multipart form data.

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
  "form_url": "https://forms-app.example.com/api/submit/contact",
  "slug": "contact",
  "name": "contact",
  "fields": [
    {"name": "email", "label": "Email", "type": "email", "required": true, ...}
  ],
  "embed_html": "<form action=\"...\" method=\"POST\">…</form>"
}
```

Error responses:

- `401 Unauthorized` — missing or invalid forms key.
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

### `POST /api/submit/{slug}` (visitor submissions)

The form owner authenticates with their forms-agent key; the snippet
carries that key as a hidden `_user_api` input so visitors can submit
without typing anything.

Returns:

- `201 Created` JSON for agents (with `{message, submission}`).
- `302 Found` redirect to `success_redirect_url` for browsers.
- `422 Unprocessable Entity` with `errors` map on validation failure.
- `429 Too Many Requests` on rate limit (per-form, per-IP, default
  60/hour — same as `/api/forms/{slug}`).

The legacy `POST /api/forms/{slug}?api_key=…` endpoint continues to
work unchanged.

## AI-discoverable docs

Two endpoints serve the same Markdown body, cached for 1 hour:

- `GET /llms.txt` — `Content-Type: text/markdown; charset=utf-8`.
- `GET /api/agent/docs` — same content as JSON
  (`{"content": "...", "format": "markdown"}`).

Both list every endpoint, the auth scheme, example requests, and
example responses. Edit `AgentDocsController::buildMarkdown()` to
change the docs and bump the cache key constant
(`AgentDocsController::CACHE_KEY`) to invalidate.

## Security notes

- Treat the forms-agent key as a password. Anyone holding it can
  create forms under the user's account.
- Revoke the key from `/dashboard/agent-key` if it leaks. Revoking
  immediately invalidates the key for all in-flight agents.
- Visitors submitting the embed snippet never see the key — the
  snippet carries it in a hidden `_user_api` input, not the URL.
- The agent response payload never contains the per-form `api_key`
  (only the user's own key, baked into `embed_html`).
- The HTML snippet is parsed with DOMDocument (with `LIBXML_NONET`
  to block external DTDs). Honeypot fields (anything inside an
  off-screen `<div>`) are stripped. Control fields prefixed with `_`
  are also stripped.
- Audit log entries are written for every key generate/revoke event
  (`forms-agent.key.generated`, `forms-agent.key.revoked`) with the
  user's IP.

## Dashboard

`/dashboard/agent-key` shows:

- Token status: "Active" with last-4 fingerprint, created-at,
  last-used-at, or "No key" with a Generate button.
- A one-time-reveal modal that shows the plaintext key once with a
  copy button. After the modal closes, the plaintext is gone.
- A revoke confirmation modal.

A sidebar item "Forms agent API" appears in the `Forms` group on the
dashboard layout.