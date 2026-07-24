<?php

namespace Tests\Feature\Api;

use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for `VerifyFormApiKey` — the per-form middleware that
 * authenticates visitor submissions against `/api/forms/{slug}`.
 *
 * The agent workflow ships the per-form `api_key` to the user's
 * static site via a hidden body field, so the middleware must read
 * from `X-Form-Key` (header), `X-Api-Key` (header), `?api_key=`
 * (query), or `api_key` (POST body) — in that priority order.
 *
 * These tests hit the real `/api/forms/{slug}` endpoint rather than
 * a probe route, so the middleware is exercised end-to-end.
 */
class VerifyFormApiKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_x_form_key_header_is_accepted(): void
    {
        $form = Form::factory()->create();

        $this->withHeaders(['X-Form-Key' => $form->api_key])
            ->getJson("/api/forms/{$form->slug}")
            ->assertOk();
    }

    public function test_x_api_key_header_is_accepted(): void
    {
        $form = Form::factory()->create();

        $this->withHeaders(['X-Api-Key' => $form->api_key])
            ->getJson("/api/forms/{$form->slug}")
            ->assertOk();
    }

    public function test_query_string_api_key_is_accepted(): void
    {
        $form = Form::factory()->create();

        $this->getJson("/api/forms/{$form->slug}?api_key=".rawurlencode($form->api_key))
            ->assertOk();
    }

    public function test_post_body_api_key_is_accepted(): void
    {
        $form = Form::factory()->create();

        $this->post("/api/forms/{$form->slug}", ['api_key' => $form->api_key])
            ->assertStatus(422);  // 422 because the body has no fields, but the middleware passed
    }

    public function test_post_body_api_key_full_submission_succeeds(): void
    {
        $form = Form::factory()->create();

        $this->post("/api/forms/{$form->slug}", [
            'api_key' => $form->api_key,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'message' => 'hello',
        ])->assertCreated();
    }

    public function test_wrong_key_in_header_returns_401(): void
    {
        $form = Form::factory()->create();

        $this->withHeaders(['X-Form-Key' => 'not-the-real-key'])
            ->getJson("/api/forms/{$form->slug}")
            ->assertStatus(401);
    }

    public function test_missing_key_returns_401(): void
    {
        $form = Form::factory()->create();

        $this->getJson("/api/forms/{$form->slug}")
            ->assertStatus(401);
    }

    public function test_forms_agent_token_does_not_authenticate_submission(): void
    {
        // The forms-agent user-key is high-privilege (creation only).
        // Submitting it as the per-form key must be rejected — only
        // the per-form api_key is valid against /api/forms/{slug}.
        $user = User::factory()->create();
        $form = Form::factory()->ownedBy($user)->create();
        $formsAgentToken = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*'])->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$formsAgentToken])
            ->getJson("/api/forms/{$form->slug}")
            ->assertStatus(401);
    }
}
