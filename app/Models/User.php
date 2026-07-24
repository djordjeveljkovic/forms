<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

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
}
