<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthenticateAgentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A small throwaway route used only by these tests to exercise the
     * `agent.key` middleware. It returns the authenticated user's id.
     */
    private function registerProbeRoute(): void
    {
        Route::middleware('agent.key')
            ->match(['get', 'post'], '/__test/agent-probe', function (Request $request): JsonResponse {
                /** @var User $user */
                $user = $request->user();

                return response()->json([
                    'ok' => true,
                    'user_id' => $user?->getKey(),
                ]);
            });
    }

    public function test_missing_key_returns_401_json(): void
    {
        $this->registerProbeRoute();

        $response = $this->getJson('/__test/agent-probe');

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Missing forms key.']);
    }

    public function test_wrong_authorization_scheme_returns_401(): void
    {
        $this->registerProbeRoute();

        $response = $this->withHeaders(['Authorization' => 'Token abc123'])
            ->getJson('/__test/agent-probe');

        $response->assertStatus(401);
    }

    public function test_bearer_header_resolves_user(): void
    {
        $this->registerProbeRoute();
        $user = User::factory()->create();
        $plain = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*'])->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$plain])
            ->getJson('/__test/agent-probe');

        $response->assertOk();
        $response->assertJson(['ok' => true, 'user_id' => $user->id]);
    }

    public function test_query_string_user_api_resolves_user(): void
    {
        $this->registerProbeRoute();
        $user = User::factory()->create();
        $plain = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*'])->plainTextToken;

        $response = $this->getJson('/__test/agent-probe?user_api='.rawurlencode($plain));

        $response->assertOk();
        $response->assertJson(['user_id' => $user->id]);
    }

    public function test_body_field_user_api_resolves_user(): void
    {
        $this->registerProbeRoute();
        $user = User::factory()->create();
        $plain = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*'])->plainTextToken;

        $response = $this->call(
            'POST',
            '/__test/agent-probe',
            ['_user_api' => $plain],
        );

        $response->assertOk();
        $this->assertSame($user->id, (int) $response->json('user_id'));
    }

    public function test_non_agent_token_is_rejected(): void
    {
        $this->registerProbeRoute();
        $user = User::factory()->create();
        $plain = $user->createToken('some-other-name', ['*'])->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$plain])
            ->getJson('/__test/agent-probe');

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Invalid or missing forms key.']);
    }

    public function test_garbage_token_is_rejected(): void
    {
        $this->registerProbeRoute();

        $response = $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
            ->getJson('/__test/agent-probe');

        $response->assertStatus(401);
    }

    public function test_token_with_deleted_user_is_rejected(): void
    {
        $this->registerProbeRoute();
        $user = User::factory()->create();
        $token = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*']);

        // Delete the user directly — Sanctum does not cascade personal
        // access tokens automatically in v4.
        PersonalAccessToken::query()->where('tokenable_id', $user->id)->delete();
        $user->delete();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])
            ->getJson('/__test/agent-probe');

        $response->assertStatus(401);
    }

    public function test_authentication_updates_last_used_at(): void
    {
        $this->registerProbeRoute();
        $user = User::factory()->create();
        $plain = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*'])->plainTextToken;

        $this->assertNull($user->formsAgentTokens()->first()->last_used_at);

        $this->withHeaders(['Authorization' => 'Bearer '.$plain])
            ->getJson('/__test/agent-probe')
            ->assertOk();

        $fresh = $user->formsAgentTokens()->first();
        $this->assertNotNull($fresh->last_used_at);
    }
}
