<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The role name reserved for site administrators.
     */
    public const ROLE_ADMIN = 'admin';

    /**
     * The role name given to regular users.
     */
    public const ROLE_USER = 'user';

    /**
     * The token name reserved for the forms-agent workflow.
     *
     * Each user has at most ONE such token. It is what they hand to an
     * AI agent so the agent can create forms on their behalf.
     */
    public const FORMS_AGENT_TOKEN_NAME = 'forms-agent';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /**
     * Get all forms-agent tokens for this user.
     *
     * Used by the settings UI to render the token list. Normally a user
     * has zero or one such token.
     *
     * @return MorphMany<PersonalAccessToken, $this>
     */
    public function formsAgentTokens(): MorphMany
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable')
            ->where('name', self::FORMS_AGENT_TOKEN_NAME)
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at');
    }

    /**
     * Get the (single) forms-agent token for this user, or null.
     */
    public function currentFormsAgentToken(): ?PersonalAccessToken
    {
        return $this->formsAgentTokens()->first();
    }

    /**
     * Determine whether this user currently has a forms-agent token.
     */
    public function hasFormsAgentToken(): bool
    {
        return $this->formsAgentTokens()->exists();
    }

    /**
     * Determine whether this user is an administrator.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN);
    }

    /**
     * Get the user's subscriptions (full history).
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->latest('id');
    }

    /**
     * Get the forms owned by this user.
     *
     * @return HasMany<Form, $this>
     */
    public function forms(): HasMany
    {
        return $this->hasMany(Form::class);
    }

    /**
     * Get the submissions made to this user's forms (via the
     * forms -> submissions relationship).
     */
    public function formSubmissions(): HasManyThrough
    {
        return $this->hasManyThrough(FormSubmission::class, Form::class);
    }

    /**
     * Get the user's currently-active subscription.
     *
     * @return HasOne<Subscription, $this>
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->active()
            ->latest('id');
    }

    /**
     * Resolve the plan this user is currently on, falling back to the
     * system's default plan if they have no active subscription.
     */
    public function currentPlan(): ?Plan
    {
        return $this->activeSubscription?->plan
            ?? Plan::query()->where('is_default', true)->first();
    }

    /**
     * Convenience accessor (`$user->plan`) wrapping currentPlan().
     */
    protected function plan(): Attribute
    {
        return Attribute::get(fn (): ?Plan => $this->currentPlan());
    }

    /**
     * Determine whether the user is on the plan with the given slug.
     */
    public function onPlan(string $slug): bool
    {
        return $this->currentPlan()?->slug === $slug;
    }

    /**
     * Whether the user has already created the maximum number of forms
     * allowed by their plan. Admins always return false.
     */
    public function hasReachedFormLimit(): bool
    {
        if ($this->isAdmin()) {
            return false;
        }

        $plan = $this->currentPlan();

        if ($plan === null || $plan->max_forms === null) {
            return false;
        }

        return Form::query()->ownedBy($this)->count() >= $plan->max_forms;
    }

    /**
     * Whether the user has already submitted the maximum number of
     * submissions allowed by their plan this month. Admins always
     * return false.
     */
    public function hasReachedMonthlySubmissionLimit(): bool
    {
        if ($this->isAdmin()) {
            return false;
        }

        $plan = $this->currentPlan();

        if ($plan === null || $plan->max_submissions_per_month === null) {
            return false;
        }

        $monthCount = FormSubmission::query()
            ->whereHas('form', fn ($q) => $q->where('user_id', $this->getKey()))
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        return $monthCount >= $plan->max_submissions_per_month;
    }
}
