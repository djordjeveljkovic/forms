<?php

namespace Tests\Feature\Api;

use App\Enums\SubmissionStatus;
use App\Models\EmailJob;
use App\Models\Form;
use App\Models\FormField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SubmitV2Test extends TestCase
{
    use RefreshDatabase;

    private function makeOwnedForm(User $user, array $overrides = []): Form
    {
        $form = Form::factory()->ownedBy($user)->create($overrides + [
            'success_redirect_url' => 'https://owner.example.com/thanks',
            'recipient_emails' => ['owner@example.com'],
            'send_email' => true,
            'store_submissions' => true,
            'auto_discover_fields' => false,
        ]);

        FormField::query()->create([
            'form_id' => $form->id,
            'name' => 'email',
            'label' => 'Email',
            'type' => 'email',
            'required' => true,
            'position' => 0,
            'is_active' => true,
        ]);

        FormField::query()->create([
            'form_id' => $form->id,
            'name' => 'message',
            'label' => 'Message',
            'type' => 'textarea',
            'required' => true,
            'position' => 1,
            'is_active' => true,
        ]);

        return $form;
    }

    private function authHeaders(User $user, string $accept = 'application/json'): array
    {
        $plain = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*'])->plainTextToken;

        return [
            'Authorization' => 'Bearer '.$plain,
            'Accept' => $accept,
        ];
    }

    public function test_missing_key_returns_401(): void
    {
        $user = User::factory()->create();
        $form = $this->makeOwnedForm($user);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->post("/api/submit/{$form->slug}", [
                'email' => 'jane@example.com',
                'message' => 'hi',
            ]);

        $response->assertStatus(401);
    }

    public function test_valid_submission_via_bearer_persists_and_returns_json(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = $this->makeOwnedForm($user);

        $response = $this->withHeaders($this->authHeaders($user))
            ->post("/api/submit/{$form->slug}", [
                'email' => 'jane@example.com',
                'message' => 'Hi there',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('message', $form->success_message);
        $response->assertJsonPath('submission.data.email', 'jane@example.com');

        $this->assertDatabaseHas('form_submissions', [
            'form_id' => $form->id,
            'status' => SubmissionStatus::Received->value,
        ]);

        $this->assertSame(1, EmailJob::query()->where('status', 'pending')->count());
    }

    public function test_valid_submission_via_query_user_api(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = $this->makeOwnedForm($user);
        $plain = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*'])->plainTextToken;

        $response = $this->post(
            "/api/submit/{$form->slug}?user_api=".rawurlencode($plain),
            [
                'email' => 'bob@example.com',
                'message' => 'Hi via query',
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertCreated();
        $this->assertDatabaseHas('form_submissions', [
            'form_id' => $form->id,
        ]);
    }

    public function test_valid_submission_via_body_user_api(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = $this->makeOwnedForm($user);
        $plain = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*'])->plainTextToken;

        $response = $this->call(
            'POST',
            "/api/submit/{$form->slug}",
            [
                '_user_api' => $plain,
                'email' => 'carol@example.com',
                'message' => 'Hi via body',
            ],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        $response->assertCreated();
        $this->assertDatabaseHas('form_submissions', [
            'form_id' => $form->id,
        ]);
    }

    public function test_form_owned_by_different_user_returns_403(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $form = $this->makeOwnedForm($owner);

        $response = $this->withHeaders($this->authHeaders($attacker))
            ->post("/api/submit/{$form->slug}", [
                'email' => 'evil@example.com',
                'message' => 'evil',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('form_submissions', [
            'form_id' => $form->id,
        ]);
    }

    public function test_unknown_form_slug_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeaders($user))
            ->post('/api/submit/does-not-exist', [
                'email' => 'jane@example.com',
                'message' => 'hi',
            ]);

        $response->assertStatus(404);
    }

    public function test_validation_failure_returns_422(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = $this->makeOwnedForm($user);

        $response = $this->withHeaders($this->authHeaders($user))
            ->post("/api/submit/{$form->slug}", [
                'email' => 'not-an-email',
                'message' => 'hi',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['data.email']);
    }

    public function test_honeypot_in_payload_is_blocked(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = $this->makeOwnedForm($user);

        $response = $this->withHeaders($this->authHeaders($user))
            ->post("/api/submit/{$form->slug}", [
                'email' => 'jane@example.com',
                'message' => 'Hi',
                // Form's default honeypot field is `website`. Filling it
                // blocks the submission silently.
                'website' => 'http://spam.example.com',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('form_submissions', [
            'form_id' => $form->id,
        ]);
    }

    public function test_browser_redirects_on_success(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $form = $this->makeOwnedForm($user, [
            'success_redirect_url' => 'https://owner.example.com/thanks',
        ]);
        $plain = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*'])->plainTextToken;

        $response = $this->call(
            'POST',
            "/api/submit/{$form->slug}",
            [
                '_user_api' => $plain,
                'email' => 'jane@example.com',
                'message' => 'Hi',
            ],
            [],
            [],
            ['HTTP_ACCEPT' => 'text/html'],
        );

        $response->assertStatus(302);
        $this->assertStringStartsWith('https://owner.example.com/thanks', $response->headers->get('Location'));
    }
}
