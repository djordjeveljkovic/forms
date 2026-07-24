# i want this workflow implemented, user signs in, generates api key, then the user can go to an ai agent that is developing it's site and say: "this is api key i got from forms app, i want to use that as forms backend on my site so you need to create site forms using this key, and then make them work make the forms submit to those created form links" then ai would checkout how forms-app wants forms submitted and what convention should be followed, ai would create a simple html form, use for form action https://forms-app/create?form_name=contact_form&user_api=api_key or maybe a hidden field in post what you think is better, once submitted to this url, server will process to see if the user exists if does check if there is no conflicting form name if not it will create form with all the passed fields, if it's a curl request return simply the newly created form link, and example form code, if it's through a browser then display a message successfully created form link to copy, and form code to copy
 and the current plan is at ~/Projects/local/mare/test_form/.pi/PLAN.md see if you can use it

_Generated: 2026-07-24T10:13:42.598Z_
_Last updated: 2026-07-24T14:13:45.554Z_

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

### 2. 2026-07-24T10:48:11.329Z

Investigated https://forms.buster.rs API. Findings:

- The site is a Laravel app (stack: Laravel + Livewire + Caddy), live at forms.buster.rs.
- API key works: GET /api/user returns authenticated user "Test User" (test@example.com, id 1).
- API routes discovered (auth required unless noted):
  - GET /api/user — returns authenticated user
  - GET /api/health — returns {"status":"ok","service":"forms-api"}
  - GET /api/forms/{slug} — public form endpoint, but requires its own API key (NOT the user token). Returns "Invalid or missing API key" with the user token.
- No documented form-creation endpoint exists. Tested POST /api/forms, /api/forms/contact, /api/forms/list, /api/workspaces, /api/v1/forms, /api/admin/forms, /api/dashboard, etc. — all 404 Route not found.
- Only documentation-ish paths are /docs, /api/docs, /documentation, /api/documentation — all 404.
- The site JS bundle is empty (0 bytes), so cannot extract client-side routes.
- Public forms appear to be created via the web UI (/login → /register). The site has only Login/Register in the home page; no docs subdomain.
- The forms use a different API key scheme: public form access seems to require a separate key (different from the user's personal token), since GET /api/forms/contact with the user token failed.

Conclusion: The API does NOT expose a documented endpoint for creating forms via API key. The user's stated workflow (create form via API key + use it later) is not supported by any endpoint I could find or by the public docs on the site. The user needs to either:
1. Point me to actual documentation (a link, PDF, repo).
2. Confirm whether they're aware that forms on this platform are created via web UI, not API.
3. Provide a different site or service (e.g., they may be confusing forms.buster.rs with another service like Tally, Typeform, Formspree, etc.).


# Admin Panel & Subscription Plans

## Overview

Add an admin role + a self-contained `/admin` section to the existing
Laravel + Livewire + Flux UI forms app. Admins can do everything a
regular user can, **plus** view global analytics, manage users
(CRUD), manage subscription plans, and impersonate users. Permissions
are gated by [Spatie Laravel Permission](https://spatie.be/docs/laravel-permission)
so the role/permission model is extensible later.

Subscription plans are first-class entities: a `plans` table describes
what each plan offers (limits, price, features), and a `subscriptions`
table links a user to a plan with status/dates. The `User` model gains
a `subscription()` relation and a `plan()` convenience accessor.

The regular user dashboard is untouched. The admin UI lives entirely
under `/admin/*` with a dedicated layout and sidebar.

---

## Decisions (locked from clarify phase)

| Question | Decision |
|---|---|
| Plan model | Full `plans` + `subscriptions` tables |
| Permissions | `spatie/laravel-permission` package |
| Admin actions | Create, edit, delete users · toggle admin · assign plan · disable 2FA / force password reset · impersonate |
| UI structure | Separate `/admin` section with own layout |

---

## 1. New dependency

Add `spatie/laravel-permission` (v6 supports Laravel 12/13).
Publishes its own migrations for `roles`, `permissions`,
`model_has_roles`, `model_has_permissions`, `role_has_permissions`.

```bash
composer require spatie/laravel-permission --no-interaction
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --tag=permission-migrations
```

---

## 2. Database migrations

Naming follows existing `2026_07_24_HHMMSS_description.php` style.

### `2026_07_24_210000_create_plans_table.php`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `name` | string | e.g. "Free", "Pro" |
| `slug` | string unique | "free", "pro" |
| `description` | text nullable | |
| `price_cents` | unsigned int default 0 | |
| `currency` | string(3) default 'USD' | |
| `interval` | string default 'monthly' | enum: monthly, yearly, one_time |
| `max_forms` | int nullable | null = unlimited |
| `max_submissions_per_month` | int nullable | null = unlimited |
| `features` | json nullable | arbitrary list of feature flags |
| `is_active` | bool default true | |
| `is_default` | bool default false | only one row should be true; seeded enforced |
| `sort` | int default 0 | display order |
| timestamps | | |

### `2026_07_24_210001_create_subscriptions_table.php`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | foreignId constrained nullOnDelete | |
| `plan_id` | foreignId constrained restrictOnDelete | |
| `status` | string default 'active' | enum: active, trialing, cancelled, expired, past_due |
| `starts_at` | timestamp | |
| `ends_at` | timestamp nullable | null = no pre-set end |
| `trial_ends_at` | timestamp nullable | |
| `cancelled_at` | timestamp nullable | |
| `metadata` | json nullable | |
| timestamps | | |
| index | (`user_id`, `status`) | |
| index | (`plan_id`) | |

Note: allow multiple historical rows per user (no DB-level uniqueness)
so admins can audit plan changes. Active subscription is resolved via
a `Subscription::active()` scope.

### Spatie migrations

Auto-published by the package, no custom changes needed.

---

## 3. Models

### `app/Models/Plan.php` (new)

```php
#[Fillable([name, slug, description, price_cents, currency, interval,
            max_forms, max_submissions_per_month, features,
            is_active, is_default, sort])]
class Plan extends Model {
    use HasFactory;

    protected function casts(): array {
        return [
            'price_cents' => 'integer',
            'max_forms' => 'integer',
            'max_submissions_per_month' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function subscriptions(): HasMany { return $this->hasMany(Subscription::class); }
    public function formattedPrice(): string { ... }
    public function hasUnlimitedForms(): bool { return $this->max_forms === null; }
    public function hasUnlimitedSubmissions(): bool { return $this->max_submissions_per_month === null; }
    public function scopeActive(Builder $q): Builder { ... }
}
```

Factory `PlanFactory` with `free()`, `pro()`, `enterprise()` states.

### `app/Models/Subscription.php` (new)

```php
#[Fillable([user_id, plan_id, status, starts_at, ends_at, trial_ends_at, cancelled_at, metadata])]
class Subscription extends Model {
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_PAST_DUE = 'past_due';

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function isActive(): bool { ... }
    public function scopeActive(Builder $q): Builder { ... }
}
```

### `app/Models/User.php` (modify)

- Add `use Spatie\Permission\Traits\HasRoles;`
- Add relations:
  - `subscriptions(): HasMany`
  - `activeSubscription(): HasOne` (active scope)
  - `plan(): Attribute` (computed from activeSubscription, falls back to default plan)
- Add helpers:
  - `isAdmin(): bool` — short for `$this->hasRole('admin')`
  - `currentPlan(): ?Plan`
  - `onPlan(string $slug): bool`
  - `hasReachedFormLimit(): bool` — checks against plan

### `app/Models/Form.php` (modify)

- Add `scopeActiveThisMonth(Builder $q): Builder` for plan-limit checks
- Add `monthlySubmissionsCount(): int` computed

(This stays close to the existing scope patterns.)

---

## 4. Authorization (Spatie)

### Roles

- `admin` — full access
- `user` — default; no special permissions minted (regular users
  authorize via existing FormPolicy / EmailJobPolicy / FormSubmissionPolicy)

### Permissions

Defined in `app/Providers/PermissionServiceProvider.php` (new) and
seeded by `RolesAndPermissionsSeeder`:

| Permission | Description |
|---|---|
| `view-admin-panel` | Enter `/admin/*` |
| `view-users` | See users index |
| `create-users` | Create new users |
| `edit-users` | Edit user profile |
| `delete-users` | Delete users |
| `assign-plans` | Assign/change plans |
| `impersonate-users` | Start impersonation |
| `view-global-analytics` | See all-users analytics |
| `reset-2fa` | Disable 2FA on a user |
| `manage-plans` | Create/edit/delete plans |

Admin role gets all permissions via `$role->givePermissionTo($all)`.

### Middleware

- `app/Http/Middleware/EnsureUserIsAdmin.php` — alias `admin`,
  delegates to `auth()->user()->can('view-admin-panel')`. Mounted on
  every `/admin/*` route.

### `AppServiceProvider::registerPolicies()`

Existing policies get an admin bypass: each `*Policy` checks
`isAdmin()` first, then falls back to the existing owner check.
This is the least-intrusive way to give admins full access without
rewriting every Livewire mount.

```php
// e.g. FormPolicy::isOwner
protected function isOwner(User $user, Form $form): bool
{
    return $user->isAdmin() || (int) $form->user_id === (int) $user->getKey();
}
```

---

## 5. Routes

New `routes/admin.php`:

```php
Route::middleware(['auth', 'verified', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', AdminDashboard::class)->name('dashboard');
        Route::livewire('/users', UsersIndex::class)->name('users.index');
        Route::livewire('/users/create', UserCreate::class)->name('users.create');
        Route::livewire('/users/{user}', UserShow::class)->name('users.show');
        Route::livewire('/users/{user}/edit', UserEdit::class)->name('users.edit');
        Route::livewire('/plans', PlansIndex::class)->name('plans.index');
        Route::livewire('/plans/create', PlanCreate::class)->name('plans.create');
        Route::livewire('/plans/{plan}/edit', PlanEdit::class)->name('plans.edit');

        // Impersonation (HTTP POSTs, not Livewire)
        Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'start'])
            ->name('users.impersonate.start');
        Route::post('/impersonate/stop', [ImpersonationController::class, 'stop'])
            ->name('users.impersonate.stop');
    });
```

Required in `routes/web.php` via `require __DIR__.'/admin.php';`.

---

## 6. Livewire components

All under `app/Livewire/Admin/`. Follow existing patterns:
`#[Title('...')]`, `#[Layout('layouts.admin')]`, `WithPagination`,
`Flux::toast`, `Flux::modal`, `audit()` log helper.

### `AdminDashboard.php`
Global KPIs: total users, total forms, total submissions (range),
signups per day (chart), active subscriptions, MRR (sum of active
subscription plan prices), recent admin actions.

### `UsersIndex.php`
Paginated table with filters: search (name/email), role filter
(admin/user), plan filter, "verified only" toggle. Row actions:
view, edit, delete (with confirm modal), impersonate (with reason
modal + audit log).

### `UserCreate.php`
Form with name, email, password (auto-generate option), role
(checkbox for admin), plan (dropdown). Triggers Fortify
`CreateNewUser` action; then assigns role + creates default
Subscription row.

### `UserEdit.php`
Edit name, email (re-verify if changed), password (optional reset),
role checkbox, plan dropdown. "Disable 2FA" button (POSTs + audit).
"Reset password" generates a temp password and emails the user
(re-uses existing mail patterns; if no mail setup, shows the temp
password once on screen).

### `UserShow.php`
Read-only drill-down: profile, current plan, subscription history,
forms owned (table), submissions count, last login IP, agent
token status, audit log entries performed by this user.

### `PlansIndex.php`
List of plans with subs count + revenue column. Sort order editing
inline. Toggle is_active. Delete (refuses if subs exist).

### `PlanCreate.php` / `PlanEdit.php`
Form for all plan fields. Enforces single `is_default` row
(unsetting other defaults on save).

---

## 7. Impersonation

Custom implementation (no extra package). Stored in session only.

### `app/Http/Controllers/Admin/ImpersonationController.php`

```php
public function start(Request $request, User $user): RedirectResponse
{
    abort_unless($request->user()->can('impersonate-users'), 403);
    abort_if($user->isAdmin() && ! $request->user()->isAdmin(), 403);
    abort_if($user->getKey() === $request->user()->getKey(), 400);

    AuditLog::create([
        'user_id' => $request->user()->getKey(),
        'action' => 'admin.impersonation.started',
        'metadata' => ['impersonated_user_id' => $user->getKey(),
                       'reason' => $request->input('reason')],
        'ip_address' => $request->ip(),
    ]);

    session(['impersonator_id' => $request->user()->getKey()]);
    Auth::login($user);

    return redirect()->route('dashboard.index');
}

public function stop(Request $request): RedirectResponse
{
    $impersonatorId = session('impersonator_id');
    abort_unless($impersonatorId, 400);

    $impersonator = User::find($impersonatorId);
    abort_unless($impersonator, 400);

    AuditLog::create([
        'user_id' => $impersonatorId,
        'action' => 'admin.impersonation.stopped',
        'metadata' => ['impersonated_user_id' => $request->user()->getKey()],
        'ip_address' => $request->ip(),
    ]);

    Auth::login($impersonator);
    session()->forget('impersonator_id');

    return redirect()->route('admin.users.index');
}
```

### `app/Http/Middleware/RecordImpersonator.php`
Records `impersonator_id` on every audit log written during
impersonation, so the trail is clean.

### `resources/views/components/impersonation-banner.blade.php`
Persistent banner shown when `session('impersonator_id')` exists.
"Stop impersonating" button posts to `/admin/users/impersonate/stop`.

### `resources/views/layouts/admin.blade.php`
Wraps the admin sidebar, includes the impersonation banner, links
back to the main dashboard, and renders `{{ $slot }}`.

---

## 8. Seeders

### `database/seeders/RolesAndPermissionsSeeder.php` (new)
Defines roles + permissions, assigns admin perms to admin role.

### `database/seeders/PlanSeeder.php` (new)
Seeds:
- **Free** — `is_default=true`, `price_cents=0`, `max_forms=3`,
  `max_submissions_per_month=100`, `features[] = ['basic']`
- **Pro** — `price_cents=1900`, `max_forms=25`, `max_submissions_per_month=10000`,
  `features[] = ['basic','captcha','custom_redirect']`
- **Enterprise** — `price_cents=9900`, `max_forms=null`,
  `max_submissions_per_month=null`, `features[] = ['basic','captcha',
  'custom_redirect','sla','priority_email']`

### `database/seeders/DatabaseSeeder.php` (modify)
Updated order:
1. `RolesAndPermissionsSeeder`
2. `PlanSeeder`
3. Upsert admin user `admin@example.com` (password `password`, role
   `admin`, default plan)
4. Upsert test user `test@example.com` (already exists, assign plan)
5. `FormSeeder`

---

## 9. Migrations & lifetime hooks

### Plan-limit enforcement

In `FormCreate`:
```php
if (! $user->isAdmin()) {
    $currentCount = Form::query()->ownedBy($user)->count();
    if ($plan->max_forms !== null && $currentCount >= $plan->max_forms) {
        $this->addError('name', 'You have reached the form limit on your plan.');
        return;
    }
}
```

In `SubmissionController::store`:
```php
if (! $user->isAdmin() && $plan?->max_submissions_per_month !== null) {
    $monthCount = FormSubmission::query()
        ->whereHas('form', fn($q) => $q->where('user_id', $user->id))
        ->where('created_at', '>=', now()->startOfMonth())
        ->count();
    if ($monthCount >= $plan->max_submissions_per_month) {
        return response()->json(['error' => 'Plan limit reached'], 429);
    }
}
```

---

## 10. Tests

### Feature
- `tests/Feature/Admin/AdminDashboardTest.php`
  - non-admin gets 403 on `/admin`
  - admin sees the dashboard with global KPIs
- `tests/Feature/Admin/UserManagementTest.php`
  - list/search/filter users
  - create user → user is created, role assigned, default sub created
  - edit user → role/plan changes persist
  - delete user → cascades forms/submissions/email jobs
  - delete self forbidden
- `tests/Feature/Admin/PlanManagementTest.php`
  - create/update/delete plan
  - only one `is_default` at a time
  - delete refused when subs exist
- `tests/Feature/Admin/ImpersonationTest.php`
  - admin can impersonate non-admin user
  - admin cannot impersonate self
  - user cannot impersonate
  - stop restores original auth
  - audit log records both halves
- `tests/Feature/Admin/PolicyAdminBypassTest.php`
  - admin may edit/delete other users' forms
  - admin may view another user's submissions
- `tests/Feature/PlanLimitsTest.php`
  - over-limit form creation blocked
  - over-limit submission blocked

### Unit
- `tests/Unit/Models/PlanTest.php` — casts, helpers, formattedPrice
- `tests/Unit/Models/SubscriptionTest.php` — isActive, scopes
- `tests/Unit/Models/UserPlanResolverTest.php` — currentPlan + fallback

---

## 11. Files inventory (new / modified)

### New
- `app/Models/Plan.php`
- `app/Models/Subscription.php`
- `app/Http/Middleware/EnsureUserIsAdmin.php`
- `app/Http/Middleware/RecordImpersonator.php`
- `app/Http/Controllers/Admin/ImpersonationController.php`
- `app/Livewire/Admin/AdminDashboard.php`
- `app/Livewire/Admin/UsersIndex.php`
- `app/Livewire/Admin/UserCreate.php`
- `app/Livewire/Admin/UserEdit.php`
- `app/Livewire/Admin/UserShow.php`
- `app/Livewire/Admin/PlansIndex.php`
- `app/Livewire/Admin/PlanCreate.php`
- `app/Livewire/Admin/PlanEdit.php`
- `app/Providers/PermissionServiceProvider.php`
- `app/Services/Admin/ImpersonationService.php` *(optional, if logic grows)*
- `resources/views/layouts/admin.blade.php`
- `resources/views/layouts/admin/sidebar.blade.php`
- `resources/views/components/impersonation-banner.blade.php`
- `resources/views/livewire/admin/admin-dashboard.blade.php`
- `resources/views/livewire/admin/users-index.blade.php`
- `resources/views/livewire/admin/user-create.blade.php`
- `resources/views/livewire/admin/user-edit.blade.php`
- `resources/views/livewire/admin/user-show.blade.php`
- `resources/views/livewire/admin/plans-index.blade.php`
- `resources/views/livewire/admin/plan-create.blade.php`
- `resources/views/livewire/admin/plan-edit.blade.php`
- `database/migrations/2026_07_24_210000_create_plans_table.php`
- `database/migrations/2026_07_24_210001_create_subscriptions_table.php`
- `database/seeders/RolesAndPermissionsSeeder.php`
- `database/seeders/PlanSeeder.php`
- `database/factories/PlanFactory.php`
- `database/factories/SubscriptionFactory.php`
- `tests/Feature/Admin/AdminDashboardTest.php`
- `tests/Feature/Admin/UserManagementTest.php`
- `tests/Feature/Admin/PlanManagementTest.php`
- `tests/Feature/Admin/ImpersonationTest.php`
- `tests/Feature/Admin/PolicyAdminBypassTest.php`
- `tests/Feature/PlanLimitsTest.php`
- `tests/Unit/Models/PlanTest.php`
- `tests/Unit/Models/SubscriptionTest.php`
- `tests/Unit/Models/UserPlanResolverTest.php`

### Modified
- `composer.json` (add `spatie/laravel-permission`)
- `app/Models/User.php` (HasRoles + relations + helpers)
- `app/Models/Form.php` (monthly-count scope)
- `app/Livewire/Dashboard/FormCreate.php` (plan-limit check)
- `app/Http/Controllers/Api/SubmissionController.php` (plan-limit check)
- `app/Policies/FormPolicy.php` (admin bypass)
- `app/Policies/FormSubmissionPolicy.php` (admin bypass)
- `app/Policies/EmailJobPolicy.php` (admin bypass)
- `app/Providers/AppServiceProvider.php` (register Permission service provider, keep policy registration)
- `bootstrap/app.php` (register `admin` middleware alias)
- `routes/web.php` (require admin routes)
- `database/seeders/DatabaseSeeder.php` (run new seeders)

---

## 12. Run / verify

```bash
# Install
composer require spatie/laravel-permission --no-interaction
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --tag=permission-migrations --force
php artisan migrate

# Seed
php artisan db:seed

# Static
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse

# Tests
php artisan test --compact --filter=Admin
php artisan test --compact --filter=PlanLimits
php artisan test --compact --filter=Plan  # PlanTest + SubscriptionTest + UserPlanResolverTest
php artisan test --compact  # full suite

# Frontend
npm run build
```

---

## 13. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Spatie permission cache stale after seeder changes | `$permission->forgetCachedPermissions()` in seeder |
| Existing tests fail when admin user also exists in DB | Seeder creates admin but tests use `User::factory()`; assert role via `->assignRole()` instead of relying on seed |
| `composer require` pulls breaking versions | Pin `spatie/laravel-permission: ^6.0` in composer.json |
| Admin can accidentally delete themselves | Ban `delete` when `$user->id === auth()->id()` in controller + Livewire action |
| Plan-limit checks add latency to submission | Check is a single `COUNT`; cheaper than the existing form-key lookup |
| Impersonation banner leaks after admin logs out | Clear `impersonator_id` on `Auth::logout()` event listener |
| Free plan missing on existing users (legacy forms) | Seeder auto-creates a `Subscription` row at the default plan for any user missing one when `DatabaseSeeder` runs |
