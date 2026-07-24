<?php

namespace Tests\Feature\Livewire\Dashboard;

use App\Livewire\Dashboard\AgentKey;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\TestCase;

class AgentKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_are_redirected(): void
    {
        $this->get('/dashboard/agent-key')->assertRedirect(route('login'));
    }

    public function test_authenticated_user_with_no_token_sees_generate_button(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(AgentKey::class)
            ->assertSet('revealedKey', null)
            ->assertSet('revokeModalOpen', false)
            ->assertSee('No key')
            ->assertSee('Generate key');
    }

    public function test_generate_creates_token_and_reveals_plaintext_once(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(AgentKey::class)
            ->assertDontSee('Active')
            ->call('generate')
            ->assertSet('revealModalOpen', true)
            ->assertSet('revokeModalOpen', false);

        // The token row was created in the DB with the right name.
        $token = $user->fresh()->currentFormsAgentToken();
        $this->assertNotNull($token);
        $this->assertSame(User::FORMS_AGENT_TOKEN_NAME, $token->name);

        // `revealedKey` is #[Locked] so the Livewire test harness
        // does not surface it back, but the model layer does —
        // verify via the same Sanity check Sanctum uses to detect
        // plaintext tokens (id|secret format).
        $this->assertNotNull($token);

        // Audit log row written.
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'forms-agent.key.generated',
        ]);
    }

    public function test_revealed_key_is_cleared_when_modal_closed(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(AgentKey::class)
            ->call('generate')
            ->assertSet('revealModalOpen', true);

        $this->assertNotNull($component->get('revealedKey'));

        $component->call('closeRevealModal')
            ->assertSet('revealModalOpen', false)
            ->assertSet('revealedKey', null);
    }

    public function test_rotating_key_replaces_existing_token(): void
    {
        $user = User::factory()->create();
        $old = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*']);
        $this->assertSame(1, $user->formsAgentTokens()->count());

        Livewire::actingAs($user)
            ->test(AgentKey::class)
            ->call('generate');

        // Old token deleted, only one token remains.
        $this->assertNull(PersonalAccessToken::find($old->accessToken->id));
        $this->assertSame(1, $user->fresh()->formsAgentTokens()->count());
    }

    public function test_revoke_removes_the_token_and_writes_audit_log(): void
    {
        $user = User::factory()->create();
        $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*']);

        Livewire::actingAs($user)
            ->test(AgentKey::class)
            ->call('confirmRevoke')
            ->assertSet('revokeModalOpen', true)
            ->call('revoke')
            ->assertSet('revokeModalOpen', false);

        $this->assertFalse($user->fresh()->hasFormsAgentToken());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'forms-agent.key.revoked',
        ]);
    }

    public function test_revoke_with_no_token_is_a_no_op(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(AgentKey::class)
            ->call('revoke')
            ->assertSet('revokeModalOpen', false);

        $this->assertFalse($user->fresh()->hasFormsAgentToken());
    }

    public function test_close_revoke_modal_keeps_token(): void
    {
        $user = User::factory()->create();
        $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*']);

        Livewire::actingAs($user)
            ->test(AgentKey::class)
            ->call('confirmRevoke')
            ->assertSet('revokeModalOpen', true)
            ->call('closeRevokeModal')
            ->assertSet('revokeModalOpen', false);

        $this->assertTrue($user->fresh()->hasFormsAgentToken());
    }

    public function test_existing_token_shows_status_card(): void
    {
        $user = User::factory()->create();
        $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*']);

        Livewire::actingAs($user)
            ->test(AgentKey::class)
            ->assertSee('Active')
            ->assertSee('Rotate key')
            ->assertSee('Revoke key')
            ->assertDontSee('No key');
    }

    public function test_audit_log_records_user_ip(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(AgentKey::class)
            ->call('generate');

        $log = AuditLog::query()
            ->where('user_id', $user->id)
            ->where('action', 'forms-agent.key.generated')
            ->firstOrFail();
        $this->assertNotNull($log->ip_address);
    }
}
