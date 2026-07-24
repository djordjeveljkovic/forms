<?php

namespace App\Livewire\Dashboard;

use App\Models\AuditLog;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Dashboard page where the signed-in user can view, generate, and
 * revoke their personal "forms-agent" token.
 *
 * The token is what the user hands to an external AI agent so it can
 * create forms on their behalf via `POST /api/agent/forms`. Plaintext
 * is shown ONCE in a modal right after generation; subsequent visits
 * show only last-4 + created-at + last-used-at.
 */
#[Title('Forms agent API key')]
class AgentKey extends Component
{
    /**
     * The plaintext token revealed to the user right after generation.
     * Held in a `Locked` property so Livewire never accepts it from
     * the client, only from a server-side action.
     */
    #[Locked]
    public ?string $revealedKey = null;

    /**
     * Whether the reveal modal is currently open.
     */
    #[Locked]
    public bool $revealModalOpen = false;

    /**
     * Whether the revoke confirmation modal is currently open.
     */
    public bool $revokeModalOpen = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        // Nothing to do on mount — Computed properties lazy-load.
    }

    /**
     * The current forms-agent token for the signed-in user, if any.
     */
    #[Computed]
    public function currentToken(): ?PersonalAccessToken
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->currentFormsAgentToken();
    }

    /**
     * Whether the signed-in user currently has a token.
     */
    #[Computed]
    public function hasToken(): bool
    {
        return $this->currentToken() !== null;
    }

    /**
     * Last 4 characters of the plaintext token, or null.
     *
     * Sanctum never stores plaintext — these are the last 4 chars of
     * the token ID rather than the secret itself. Good enough for the
     * "key ending in …abcd" display pattern.
     */
    #[Computed]
    public function tokenFingerprint(): ?string
    {
        $token = $this->currentToken();
        if ($token === null) {
            return null;
        }

        // The `id` column on personal_access_tokens is the visible
        // portion of the token. Take the last 4 characters for the
        // fingerprint.
        $id = (string) $token->id;

        return strlen($id) >= 4 ? substr($id, -4) : $id;
    }

    /**
     * Generate a new forms-agent token for the user.
     *
     * Any existing token is deleted first so the user has at most one
     * active key — the personal-access-token system has no concept of
     * "rotate in place" without first issuing a new one.
     */
    public function generate(): void
    {
        /** @var User $user */
        $user = Auth::user();

        // Delete any existing token. A user has at most one active
        // forms-agent token at a time.
        $user->formsAgentTokens()->delete();

        $newToken = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*']);

        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'forms-agent.key.generated',
            'metadata' => ['ip' => request()->ip()],
            'ip_address' => request()->ip(),
        ]);

        $this->revealedKey = $newToken->plainTextToken;
        $this->revealModalOpen = true;

        Flux::toast(variant: 'success', text: __('API key generated.'));
    }

    /**
     * Close the reveal modal and clear the plaintext from memory.
     *
     * Once the modal is closed, the plaintext is gone — the user has
     * to generate a new token to see it again.
     */
    public function closeRevealModal(): void
    {
        $this->revealModalOpen = false;
        $this->revealedKey = null;
    }

    /**
     * Open the revoke confirmation modal.
     */
    public function confirmRevoke(): void
    {
        if (! $this->hasToken()) {
            return;
        }

        $this->revokeModalOpen = true;
    }

    /**
     * Revoke the user's current forms-agent token.
     */
    public function revoke(): void
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->hasFormsAgentToken()) {
            $this->revokeModalOpen = false;

            return;
        }

        $user->formsAgentTokens()->delete();

        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'forms-agent.key.revoked',
            'metadata' => ['ip' => request()->ip()],
            'ip_address' => request()->ip(),
        ]);

        $this->revokeModalOpen = false;
        $this->revealedKey = null;

        Flux::toast(variant: 'success', text: __('API key revoked.'));
    }

    /**
     * Close the revoke modal without revoking.
     */
    public function closeRevokeModal(): void
    {
        $this->revokeModalOpen = false;
    }

    /**
     * Render the component.
     */
    public function render(): View
    {
        return view('livewire.dashboard.agent-key');
    }
}
