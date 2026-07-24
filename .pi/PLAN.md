# i want this workflow implemented, user signs in, generates api key, then the user can go to an ai agent that is developing it's site and say: "this is api key i got from forms app, i want to use that as forms backend on my site so you need to create site forms using this key, and then make them work make the forms submit to those created form links" then ai would checkout how forms-app wants forms submitted and what convention should be followed, ai would create a simple html form, use for form action https://forms-app/create?form_name=contact_form&user_api=api_key or maybe a hidden field in post what you think is better, once submitted to this url, server will process to see if the user exists if does check if there is no conflicting form name if not it will create form with all the passed fields, if it's a curl request return simply the newly created form link, and example form code, if it's through a browser then display a message successfully created form link to copy, and form code to copy
 and the current plan is at ~/Projects/local/mare/test_form/.pi/PLAN.md see if you can use it

_Generated: 2026-07-24T10:13:42.598Z_
_Last updated: 2026-07-24T10:18:13.512Z_

## Context

Project: `/home/usrtmp/Projects/local/mare/forms`

## Clarifications

### 1. Where should the user-facing "Manage API key" page live? The existing plan puts the AgentKey Livewire component under `/dashboard/settings/agent-key`. Two natural options:

A. Add a new sidebar item `Forms agent API` under the dashboard `Forms` group, route `/dashboard/agent-key` — keeps it discoverable next to other form management.
B. Add it under the existing `Account → Settings` group, route `/settings/agent-key` (alongside Profile, Security) — matches the existing settings pattern.

Which fits your mental model better?

**Answer:** A. /dashboard/agent-key — Forms sidebar group


## Research Notes

### 1. 2026-07-24T10:15:02.193Z

State of the codebase (2026-07-24): substantial portions of the original test_form/.pi/PLAN.md are ALREADY implemented.

## Already done

**Schema**
- `database/migrations/2026_07_24_200000_add_user_id_to_forms_table.php` — adds user_id FK (nullOnDelete), drops global unique on slug, adds composite unique (user_id, slug), back-fills legacy forms with first user.

**User model** (`app/Models/User.php`)
- `HasApiTokens` trait (Sanctum 4)
- `FORMS_AGENT_TOKEN_NAME = 'forms-agent'` constant
- `formsAgentTokens()` morphMany relation filtered to that name
- `currentFormsAgentToken()` helper
- `hasFormsAgentToken()` helper

**Form model** (`app/Models/Form.php`)
- `user_id` in Fillable + Casts; BelongsTo User
- `scopeOwnedBy(User)` builder scope
- `generateUniqueSlug(name, userId)` — scoped per-user
- FormFactory has `ownedBy(User)` state

**Agent services**
- `app/Services/Agent/FormHtmlParser.php` — full DOMDocument-based parser with honeypot detection, control-field skipping, label/help-text resolution, type mapping.
- `app/Services/Agent/EmbedSnippetGenerator.php` — emits a copy-pasteable HTML snippet that posts to `/api/submit/{slug}` with a hidden `_user_api` field, honeypot, all configured fields, redirect/timestamp controls.

## Still missing

- `AuthenticateAgent` middleware (reads Bearer / ?user_api= / body _user_api, looks up PersonalAccessToken by hash, ensures name='forms-agent')
- `app/Http/Controllers/Api/AgentFormController.php` (POST /api/agent/forms)
- `app/Http/Controllers/Api/SubmissionV2Controller.php` (POST /api/submit/{slug})
- `app/Http/Controllers/Api/AgentDocsController.php` (GET /llms.txt, GET /api/agent/docs)
- `bootstrap/app.php` alias `agent.key` → AuthenticateAgent
- Routes: `/llms.txt`, `/api/agent/docs`, `/api/agent/forms`, `/api/submit/{slug}` (and maybe a GET read of /api/submit/{slug} for schema)
- `app/Livewire/Settings/AgentKey.php` (generate/revoke forms-agent token, show one-time copy modal)
- `resources/views/livewire/settings/agent-key.blade.php`
- `resources/views/agent/form-created.blade.php` (inline success page with copy buttons)
- Dashboard route for the AgentKey page + sidebar link
- Tests: `tests/Feature/Api/AgentFormStoreTest.php`, `tests/Feature/Api/SubmitV2Test.php`, `tests/Feature/Livewire/Settings/AgentKeyTest.php`, `tests/Unit/Services/Agent/FormHtmlParserTest.php` (parser already exists but no tests), `tests/Unit/Services/Agent/EmbedSnippetGeneratorTest.php`

## Decisions already captured in test_form/.pi/PLAN.md (Phase 2)

1. Auth scheme: Bearer preferred, ?user_api= fallback
2. HTML transport: multipart/form `html=<snippet>`
3. Form-name conflict: per-user (composite unique)
4. AI docs: llms.txt index → /api/agent/docs full schema
5. Browser success: inline page with copy buttons
6. Per-form api_key in response: hidden
7. Embed auth: /api/submit/{slug} accepts ?user_api= OR hidden _user_api field

## Conventions to follow

- Test classes are PHPUnit, not Pest — `use RefreshDatabase;`, `Form::factory()`, `$this->postJson(...)`, `$this->assertDatabaseHas(...)`.
- Controllers in `app/Http/Controllers/Api/`, services in `app/Services/` (or sub-namespace for Agent).
- Livewire components use `#[Title(...)]`, Flux UI, `Flux::toast(...)`, `Flux::modal(...)` patterns (see Security.php).
- Settings Livewire live in `app/Livewire/Settings/` and are mounted via `routes/settings.php`. The dashboard sidebar lives in `resources/views/layouts/app/sidebar.blade.php` and currently has a Settings item pointing at `profile.edit`.
- SubmissionController has helper methods (`wantsHtmlResponse`, `extractSubmissionData`, `resolveRedirectUrl`) that the new SubmissionV2Controller should reuse (probably by extracting them to a small helper trait) so the existing endpoint stays consistent.


# Forms-app agent integration — implementation plan

> **Source of truth:** this plan extends the more general draft at
> `~/Projects/local/mare/test_form/.pi/PLAN.md` (clarifications 1–7).
> Roughly half of that draft is already implemented in this repo; this
> document focuses on the remaining work.

## Goal & scope

A user signs in to **forms-app** (Laravel 13 + Livewire + Fortify, this
repo), generates a personal **forms-agent key** (`forms_sk_…`,
Sanctum-backed), hands that key to any external AI agent, and the agent
can create forms on the user's behalf by POSTing a raw HTML snippet.
The agent receives a public submission URL plus a copy-pasteable HTML
snippet it drops onto the user's static site; visitors who fill out the
snippet land in the existing `form_submissions` table and trigger the
existing email pipeline.

### In scope

1. **AuthenticateAgent middleware** — Bearer header / `?user_api=` query
   / `_user_api` body field → resolve a `forms-agent` Sanctum token →
   attach the owning `User` to the request.
2. **`POST /api/agent/forms`** — parse the supplied HTML, persist the
   form under the calling user, return `{form_url, slug, name, fields,
   embed_html}` (no per-form api_key).
3. **`POST /api/submit/{slug}`** — user-key-authenticated submission
   endpoint. Reuses `FormSubmissionService` for spam/validation/storage.
4. **`GET /llms.txt` + `GET /api/agent/docs`** — AI-discoverable docs.
5. **`/dashboard/agent-key`** — Livewire page (Forms sidebar group) for
   generating / revoking the personal forms-agent token.
6. Inline success view for browser-form `POST /api/agent/forms`
   (copy-to-clipboard buttons for URL + snippet).
7. Tests for everything new.

### Out of scope

- Deprecating the legacy per-form `api_key` on `/api/forms/{slug}`.
- Changing the spam / email pipeline.
- Changing any existing Livewire dashboard pages.

### Clarified today

- **Dashboard placement:** `Forms sidebar group → /dashboard/agent-key`
  (decision A from the question).

---

## Architecture

```
┌──────────────┐  POST /api/agent/forms   ┌─────────────────────────┐
│  AI agent    │  Authorization: Bearer   │  forms-app              │
│  (curl/fetch)│  forms_sk_…              │  (Laravel 13, this repo)│
│              │  html=…&form_name=…      │                         │
└──────────────┘ ◄─────────────────────── └─────────────────────────┘
                  JSON / inline HTML page with
                  {form_url, slug, fields, embed_html}
                                  │
                                  ▼  user pastes snippet on their site
                            ┌──────────────────────┐
                            │ User's static site   │
                            │  <form action=       │
                            │   /api/submit/contact>│
                            │  <input _user_api …> │
                            │  …fields… </form>    │
                            └──────────────────────┘
                                  │
                                  ▼ POST (browser)
                            ┌──────────────────────┐
                            │ forms-app            │
                            │ _user_api → user     │
                            │ FormSubmissionService│
                            └──────────────────────┘
```

---

## Implementation phases

### Phase A — AuthenticateAgent middleware

**A.1 `app/Http/Middleware/AuthenticateAgent.php`**

```php
class AuthenticateAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $this->extractKey($request);

        if ($provided === null) {
            return $this->unauth($request, 'Missing forms key.');
        }

        $token = PersonalAccessToken::findToken($provided);
        if ($token === null || $token->name !== User::FORMS_AGENT_TOKEN_NAME) {
            return $this->unauth($request, 'Invalid or missing forms key.');
        }

        $user = $token->tokenable;
        if (! $user instanceof User) {
            return $this->unauth($request, 'Invalid or missing forms key.');
        }

        $request->setUserResolver(fn () => $user);

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
    // extractKey() reads Authorization Bearer / ?user_api= / body _user_api
    // unauth() returns 401 JSON for api/* and aborts otherwise.
}
```

**A.2 `bootstrap/app.php`** — register the alias:

```php
$middleware->alias([
    'form.key' => VerifyFormApiKey::class,
    'agent.key' => AuthenticateAgent::class,
]);
```

**A.3 Tests** — `tests/Feature/Api/AuthenticateAgentTest.php`:

- Missing header → 401 JSON.
- Wrong scheme (`Token …`) → 401.
- Valid Bearer → user resolved, request continues.
- Valid `?user_api=` → user resolved.
- Valid `_user_api` body field → user resolved.
- Token whose `name` is **not** `forms-agent` → 401.
- Token for a deleted user → 401.

---

### Phase B — Agent-facing controller + routes

**B.1 `app/Http/Controllers/Api/AgentFormController.php`**

- Validates `form_name` (regex `^[a-zA-Z0-9 _\-]+$`, max 80) + `html`
  (max 65 KB) + optional `description`, `recipient_emails`, `from_*`,
  `success_redirect_url`, `success_message`.
- 409 on per-user slug conflict.
- 422 on parser RuntimeException.
- Transaction: create `Form`, then `FormField` rows in document order.
- Returns JSON `{form_url, slug, name, fields, embed_html}` for agents,
  or renders `resources/views/agent/form-created.blade.php` for browsers.

**B.2 `app/Http/Controllers/Api/SubmissionV2Controller.php`**

Thin wrapper around a `SubmissionV2Pipeline` that:

- Verifies `$form->user_id === $request->user()->id` (else 403).
- Calls `FormSubmissionService::submit()` with the same
  `extractSubmissionData` / `resolveRedirectUrl` / `wantsHtmlResponse`
  helpers the legacy `SubmissionController` uses (now extracted to a
  trait).

**B.3 `app/Http/Controllers/Api/Concerns/HandlesSubmissionResponses.php`**

Extract `extractSubmissionData`, `resolveRedirectUrl`, `wantsHtmlResponse`,
`redirectWithError`, and `originIsForbidden` from
`SubmissionController` into a trait used by **both** controllers. This
keeps behaviour identical and shrinks the legacy controller.

**B.4 Routes — `routes/api.php` additions**

```php
Route::get('/llms.txt', [AgentDocsController::class, 'llms'])
    ->name('api.agent.llms');

Route::get('/api/agent/docs', [AgentDocsController::class, 'docs'])
    ->name('api.agent.docs');

Route::middleware(['agent.key'])->group(function (): void {
    Route::post('/api/agent/forms', [AgentFormController::class, 'store'])
        ->name('api.agent.forms.store');

    Route::post('/api/submit/{form:slug}', [SubmissionV2Controller::class, 'store'])
        ->middleware('throttle:forms')
        ->name('api.submit.store');
});
```

**B.5 `app/Http/Controllers/Api/AgentDocsController.php`**

- `llms()` — cached Markdown (`Cache::remember('agent-llms', 3600, …)`)
  documenting conventions + each endpoint with signature, auth, example
  curl, example response.
- `docs()` — same content as JSON `{ content: "..." }`.

**B.6 `resources/views/agent/form-created.blade.php`**

Minimal Flux-styled page: heading + three cards (URL with copy button,
embed snippet in `<pre><code>` with copy button, next-step links to
dashboard pages). Extends `components.layouts.app`.

**B.7 Feature tests**

`tests/Feature/Api/AgentFormStoreTest.php`:

- Auth: 401 paths (missing key, wrong scheme, non-`forms-agent` token).
- Validation: missing `html` / `form_name`, oversized html.
- Conflict: same user → 409; different users → 201 each.
- HTML parsing: text + email + textarea + select → 4 fields persisted
  with correct types. Honeypot skipped.
- Response shape (curl): JSON with `form_url`, `slug`, `fields`,
  `embed_html`; no `api_key`.
- Response shape (browser): HTML page with copy buttons.

`tests/Feature/Api/SubmitV2Test.php`:

- Happy path via `?user_api=` → 201/302.
- Happy path via `_user_api` body → 201/302.
- Mismatched owner → 403.
- Missing key → 401.
- Honeypot in payload → blocked.
- Submission row + email job created.

`tests/Unit/Services/Agent/FormHtmlParserTest.php`:

- Plain input, email, textarea, select with options.
- `<label for="x">` lookup.
- Honeypot container exclusion.
- `_`-prefixed control field skip; `cf-turnstile-response` skip.
- Empty snippet → RuntimeException.
- Duplicate names collapse to first.

`tests/Unit/Services/Agent/EmbedSnippetGeneratorTest.php`:

- Snippet contains `action="/api/submit/{slug}"` + hidden `_user_api`.
- All active fields rendered.
- Honeypot included.
- `_timestamp` included when `min_submission_seconds > 0`.
- `useQueryString=true` puts key in URL.

---

### Phase C — User-facing token management

**C.1 `app/Livewire/Dashboard/AgentKey.php`** (Livewire 4, Flux UI)

- Status row: "No key generated" / "Active key created <date>".
- `generate()`:
  - Delete existing `forms-agent` tokens for the user.
  - Create new via `$user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*'])`
    (no expiry).
  - Capture plaintext from `$newToken->plainTextToken`.
  - Store in `#[Locked] public ?string $revealedKey = null`.
  - Open modal with copy button.
  - Write `AuditLog` row (`event='forms-agent.key.generated'`).
- `closeRevealModal()` clears `$revealedKey`.
- `revoke()`: delete the token, write
  `AuditLog` row (`event='forms-agent.key.revoked'`), close modal.

**C.2 `resources/views/livewire/dashboard/agent-key.blade.php`**

- Status card with `flux:button` for generate/revoke.
- `flux:modal` for the one-time plaintext key view.
- Help text about what the key does and revoking breaking in-flight
  agents.

**C.3 Routes**

- `routes/web.php` — add under the existing `Route::prefix('dashboard')`:
  ```php
  Route::livewire('/agent-key', \App\Livewire\Dashboard\AgentKey::class)
      ->name('agent-key');
  ```
- `resources/views/layouts/app/sidebar.blade.php` — add
  `<flux:sidebar.item icon="key">` inside the `Forms` group, pointing
  at `route('dashboard.agent-key')`.

**C.4 Feature test** — `tests/Feature/Livewire/Dashboard/AgentKeyTest.php`:

- Unauthenticated redirect.
- Authenticated user with no token → "Generate" visible.
- Click generate → token row in `personal_access_tokens`; modal opens
  with plaintext.
- Revoke → token removed; modal closes; audit rows exist.

---

### Phase D — Verification & docs

- `vendor/bin/pint --dirty --format agent` after every PHP edit.
- `php artisan test --compact <file>` per phase.
- Manual smoke test (documented in `docs/agent-api.md`):
  1. `php artisan serve` (or `docker compose up`).
  2. Sign up a fresh user.
  3. Visit `/dashboard/agent-key` → generate key.
  4. From terminal:
     ```bash
     curl -X POST -H "Authorization: Bearer forms_sk_…" \
          -F 'form_name=contact' \
          -F 'html=<input name="email" type="email" required>' \
          http://localhost:8000/api/agent/forms
     ```
     Expect 201 + JSON with `form_url`, `embed_html`.
  5. Paste `embed_html` into a scratch HTML file → open in browser →
     submit → verify submission in `/dashboard/submissions/{id}`.
  6. Open `/llms.txt` → confirm Markdown renders.
- Add `docs/agent-api.md` with curl example + security notes (treat
  the key as a password, revoke on rotation).

---

## Files added / modified

### New

```
app/Http/Controllers/Api/AgentFormController.php
app/Http/Controllers/Api/SubmissionV2Controller.php
app/Http/Controllers/Api/AgentDocsController.php
app/Http/Controllers/Api/Concerns/HandlesSubmissionResponses.php
app/Http/Middleware/AuthenticateAgent.php
app/Livewire/Dashboard/AgentKey.php
resources/views/agent/form-created.blade.php
resources/views/livewire/dashboard/agent-key.blade.php
tests/Feature/Api/AuthenticateAgentTest.php
tests/Feature/Api/AgentFormStoreTest.php
tests/Feature/Api/SubmitV2Test.php
tests/Feature/Livewire/Dashboard/AgentKeyTest.php
tests/Unit/Services/Agent/FormHtmlParserTest.php
tests/Unit/Services/Agent/EmbedSnippetGeneratorTest.php
docs/agent-api.md
```

### Modified

```
app/Http/Controllers/Api/SubmissionController.php    (use the new trait)
bootstrap/app.php                                     (alias agent.key)
routes/api.php                                        (4 new routes)
routes/web.php                                        (/dashboard/agent-key)
resources/views/layouts/app/sidebar.blade.php         (Forms sidebar item)
```

---

## Risks & open questions

| Risk | Mitigation |
|---|---|
| DOMDocument breaks on malformed HTML | `FormHtmlParser` already uses `libxml_use_internal_errors(true)` + `LIBXML_NONET`. Unit tests cover malformed snippets. |
| User crafts HTML that imports a remote resource at parse time | `LIBXML_NONET` blocks external DTDs; we never write the parsed DOM back to the response. |
| Honeypot detection misses a CSS variation | Already covers `position:absolute;left:-9999px` (matches snippet generator); falls back to name-based heuristic (`honeypot_field` default `website`). |
| Existing legacy forms in DB have a stale unique slug | Migration already back-fills `user_id`; the legacy `forms_slug_unique` index is dropped. |
| AI agent retries create-form with the same name | First 201, second 409; agent can retry with `_2` suffix. |
| Plain HTML form posts from external sites won't carry CSRF | New endpoint accepts a user key (not a session), so CSRF doesn't apply. Spam protection still runs. |
| Token exposure in the embed snippet | Snippet generator uses a hidden `_user_api` field by default (per clarification 7). `useQueryString=true` mode is opt-in. |

### Deferred (low priority — not in v1)

- `DELETE /api/agent/forms/{slug}` — owner revokes via dashboard.
- Token expiry (`null` vs 90 days) — keep `null` for v1.
- Back-compat for `X-Form-Key` on `/api/submit/{slug}` — out of scope;
  the legacy `/api/forms/{slug}` keeps working unchanged.

---

## Acceptance criteria

1. ✅ A new user can sign up, sign in, visit `/dashboard/agent-key`, and
   generate a `forms_sk_…` key. The plaintext key is shown once in a
   modal with a copy button; subsequent visits show only last-4 +
   created-at.
2. ✅ An external agent can `POST /api/agent/forms` with a Bearer token
   + multipart `html` + `form_name` and receive
   `{form_url, slug, name, fields, embed_html}` (no per-form api_key in
   the payload).
3. ✅ The returned `embed_html`, pasted into a static HTML page and
   submitted by a real visitor, creates a `form_submissions` row visible
   in the dashboard and triggers the existing email pipeline.
4. ✅ Two different users can each create a form named `contact_form`
   without slug conflict; the same user cannot create two with the same
   name (409).
5. ✅ `GET /llms.txt` returns Markdown listing all agent endpoints with
   examples; `GET /api/agent/docs` returns the same as JSON.
6. ✅ All new code passes `php artisan test --compact` and is covered by
   feature/unit tests.
7. ✅ Legacy `POST /api/forms/{slug}?api_key=…` continues to work
   unchanged.
8. ✅ Browser flow to `POST /api/agent/forms` (`Accept: text/html`)
   returns an inline Flux page with copy-to-clipboard buttons for the
   URL and embed snippet.

---

## Estimated effort

~1.5–2 focused days. The HTML parser and snippet generator are already
implemented; this plan mostly wires the missing controller layer, the
user-facing key management UI, the docs, and the tests.

Implementation order:

1. Phase A (middleware + tests) — ~3 hrs.
2. Phase B (controllers, routes, traits, success view, tests) — ~6 hrs.
3. Phase C (Livewire key-management page + sidebar + test) — ~3 hrs.
4. Phase D (docs + manual smoke test) — ~1 hr.