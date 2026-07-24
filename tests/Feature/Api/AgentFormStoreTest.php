<?php

namespace Tests\Feature\Api;

use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentFormStoreTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        $plain = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*'])->plainTextToken;

        // Explicitly request JSON so the controller takes the
        // programmatic-response branch instead of rendering the
        // browser success page.
        return [
            'Authorization' => 'Bearer '.$plain,
            'Accept' => 'application/json',
        ];
    }

    private function sampleHtml(): string
    {
        return <<<'HTML'
<form action="https://example.com/x" method="POST">
    <label for="email">Email</label>
    <input id="email" type="email" name="email" required placeholder="you@example.com">
    <label for="message">Message</label>
    <textarea id="message" name="message" required></textarea>
    <label for="topic">Topic</label>
    <select id="topic" name="topic" required>
        <option value="">Choose</option>
        <option>Sales</option>
        <option>Support</option>
    </select>
    <button type="submit">Send</button>
</form>
HTML;
    }

    public function test_missing_key_returns_401(): void
    {
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/agent/forms', [
                'form_name' => 'contact',
                'html' => $this->sampleHtml(),
            ]);

        $response->assertStatus(401);
    }

    public function test_non_agent_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('something-else', ['*'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$plain,
            'Accept' => 'application/json',
        ])->post('/api/agent/forms', [
            'form_name' => 'contact',
            'html' => $this->sampleHtml(),
        ]);

        $response->assertStatus(401);
    }

    public function test_missing_html_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeaders($user))
            ->post('/api/agent/forms', [
                'form_name' => 'contact',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('html');
    }

    public function test_missing_form_name_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeaders($user))
            ->post('/api/agent/forms', [
                'html' => $this->sampleHtml(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('form_name');
    }

    public function test_invalid_form_name_characters_return_422(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeaders($user))
            ->post('/api/agent/forms', [
                'form_name' => 'has/slash',
                'html' => $this->sampleHtml(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('form_name');
    }

    public function test_oversized_html_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeaders($user))
            ->post('/api/agent/forms', [
                'form_name' => 'contact',
                'html' => str_repeat('x', 65536),
            ]);

        $response->assertStatus(422);
    }

    public function test_happy_path_creates_form_and_returns_json(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeaders($user))
            ->post('/api/agent/forms', [
                'form_name' => 'contact',
                'html' => $this->sampleHtml(),
                'description' => 'Public contact form',
                'recipient_emails' => 'sales@example.com,support@example.com',
                'success_redirect_url' => 'https://example.com/thanks',
            ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'form_url',
            'slug',
            'name',
            'fields',
            'embed_html',
        ]);
        $response->assertJsonPath('slug', 'contact');
        $response->assertJsonPath('name', 'contact');

        // Per-form api_key is intentionally hidden from the agent.
        $decoded = $response->json();
        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('api_key', $decoded);

        $this->assertDatabaseHas('forms', [
            'user_id' => $user->id,
            'name' => 'contact',
            'slug' => 'contact',
            'description' => 'Public contact form',
            'success_redirect_url' => 'https://example.com/thanks',
        ]);
        $this->assertSame(
            ['sales@example.com', 'support@example.com'],
            Form::query()->where('slug', 'contact')->firstOrFail()->recipient_emails,
        );

        // Three fields persisted (email, message, topic), in order.
        $form = Form::query()->where('slug', 'contact')->firstOrFail();
        $this->assertSame(3, $form->fields()->count());

        $email = $form->fields()->where('name', 'email')->firstOrFail();
        $this->assertSame('email', $email->type);
        $this->assertTrue($email->required);
        $this->assertSame('you@example.com', $email->placeholder);
        $this->assertSame('Email', $email->label);

        $topic = $form->fields()->where('name', 'topic')->firstOrFail();
        $this->assertSame('select', $topic->type);
        $this->assertSame(['Choose', 'Sales', 'Support'], $topic->options);

        // Auto-discover is disabled — the agent did the work.
        $this->assertFalse((bool) $form->auto_discover_fields);

        // The embed snippet uses the same key the agent posted.
        $this->assertStringContainsString('action="'.url('/api/submit/contact').'"', $response->json('embed_html'));
        $this->assertStringContainsString('name="_user_api"', $response->json('embed_html'));
    }

    public function test_honeypot_field_in_snippet_is_skipped(): void
    {
        $user = User::factory()->create();

        $html = <<<'HTML'
<form method="POST">
    <input type="email" name="email" required>
    <div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
        <label>Website <input type="text" name="website" tabindex="-1"></label>
    </div>
    <button type="submit">Send</button>
</form>
HTML;

        $response = $this->withHeaders($this->authHeaders($user))
            ->post('/api/agent/forms', [
                'form_name' => 'contact',
                'html' => $html,
            ]);

        $response->assertCreated();
        $form = Form::query()->where('slug', 'contact')->firstOrFail();
        $this->assertSame(1, $form->fields()->count());
        $this->assertSame('email', $form->fields()->first()->name);
    }

    public function test_empty_snippet_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeaders($user))
            ->post('/api/agent/forms', [
                'form_name' => 'contact',
                'html' => '<p>Nothing here.</p>',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'The supplied HTML contains no usable form fields.');
    }

    public function test_duplicate_form_name_for_same_user_returns_409(): void
    {
        $user = User::factory()->create();
        Form::factory()->ownedBy($user)->create(['name' => 'contact', 'slug' => 'contact']);

        $response = $this->withHeaders($this->authHeaders($user))
            ->post('/api/agent/forms', [
                'form_name' => 'contact',
                'html' => $this->sampleHtml(),
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('form_name', 'contact');
    }

    public function test_same_form_name_for_different_users_succeeds(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $aliceResponse = $this->withHeaders($this->authHeaders($alice))
            ->post('/api/agent/forms', [
                'form_name' => 'contact',
                'html' => $this->sampleHtml(),
            ]);
        $bobResponse = $this->withHeaders($this->authHeaders($bob))
            ->post('/api/agent/forms', [
                'form_name' => 'contact',
                'html' => $this->sampleHtml(),
            ]);

        $aliceResponse->assertCreated();
        $bobResponse->assertCreated();
        $this->assertSame(2, Form::query()->where('slug', 'contact')->count());
    }

    public function test_browser_flow_returns_html_success_page(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*'])->plainTextToken;

        // Browser form post: multipart body with _user_api (the way
        // curl-without-Accept works) and Accept: text/html.
        $response = $this->call(
            'POST',
            '/api/agent/forms',
            [
                '_user_api' => $plain,
                'form_name' => 'contact',
                'html' => $this->sampleHtml(),
            ],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'text/html',
            ],
        );

        $response->assertOk();
        $response->assertSee('Your form is ready', false);
        $response->assertSee('Public submission URL', false);
        $response->assertSee('Embed snippet', false);
        $response->assertSee(url('/api/submit/contact'), false);
        // The browser path substitutes a placeholder so we don't leak
        // the user's plaintext key in the HTML response.
        $response->assertSee('__YOUR_FORMS_KEY__', false);
        $response->assertDontSee($plain, false);
    }

    public function test_recipients_default_to_user_email_when_omitted(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->withHeaders($this->authHeaders($user))
            ->post('/api/agent/forms', [
                'form_name' => 'contact',
                'html' => $this->sampleHtml(),
            ])
            ->assertCreated();

        $form = Form::query()->where('slug', 'contact')->firstOrFail();
        $this->assertSame(['owner@example.com'], $form->recipient_emails);
    }

    public function test_invalid_recipient_emails_are_filtered_out(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->withHeaders($this->authHeaders($user))
            ->post('/api/agent/forms', [
                'form_name' => 'contact',
                'html' => $this->sampleHtml(),
                'recipient_emails' => 'good@example.com, not-an-email ,also-good@example.com',
            ])
            ->assertCreated();

        $form = Form::query()->where('slug', 'contact')->firstOrFail();
        $this->assertSame(['good@example.com', 'also-good@example.com'], $form->recipient_emails);
    }

    public function test_field_position_is_in_document_order(): void
    {
        $user = User::factory()->create();

        $html = <<<'HTML'
<form method="POST">
    <input name="last" type="text">
    <input name="first" type="text">
    <input name="middle" type="text">
</form>
HTML;

        $this->withHeaders($this->authHeaders($user))
            ->post('/api/agent/forms', [
                'form_name' => 'multi',
                'html' => $html,
            ])
            ->assertCreated();

        $form = Form::query()->where('slug', 'multi')->firstOrFail();
        $fields = $form->fields()->orderBy('position')->get();
        $this->assertSame(['last', 'first', 'middle'], $fields->pluck('name')->all());
    }
}
