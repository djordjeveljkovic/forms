<?php

namespace Tests\Feature\Api;

use App\Models\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for the layered spam protection (honeypot + min submission time
 * + optional Cloudflare Turnstile).
 */
class SpamProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_honeypot_field_must_be_empty(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
            'honeypot_field' => 'website',
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => [
                'email' => 'jane@example.com',
                'website' => 'https://spam.example.com',
            ],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertStatus(422);
        $this->assertSame('Submission could not be processed.', $response->json('message'));
        $this->assertDatabaseCount('form_submissions', 0);
    }

    public function test_honeypot_default_field_name_is_website(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
            'honeypot_field' => 'website',
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com'],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertCreated();
        $this->assertDatabaseCount('form_submissions', 1);
    }

    public function test_honeypot_field_name_is_per_form_configurable(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
            'honeypot_field' => 'company_url',
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        // Filling the configured honeypot field triggers a spam rejection.
        $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com', 'company_url' => 'https://spam.test'],
        ], ['X-Form-Key' => $form->api_key])->assertStatus(422);

        // An empty / absent honeypot field is fine.
        $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com'],
        ], ['X-Form-Key' => $form->api_key])->assertCreated();
    }

    public function test_min_submission_time_rejects_quick_post(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 5,
            'honeypot_field' => 'website',
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        // No timestamp provided.
        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com'],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertStatus(400);
        $this->assertSame('Submission could not be processed.', $response->json('message'));
    }

    public function test_min_submission_time_rejects_timestamp_in_the_future(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 5,
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com'],
            '_timestamp' => time() + 60, // 1 minute in the future
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertStatus(400);
    }

    public function test_min_submission_time_rejects_replayed_old_timestamp(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 5,
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        // Two days ago — well outside the 24h replay window.
        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com'],
            '_timestamp' => time() - 86400 * 2,
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertStatus(400);
    }

    public function test_min_submission_time_disabled_when_zero(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com'],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertCreated();
    }

    public function test_min_submission_time_passes_when_old_enough(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 2,
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com'],
            '_timestamp' => time() - 10, // 10 seconds ago, well past the 2s threshold
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertCreated();
    }

    public function test_turnstile_verification_fails_when_token_missing(): void
    {
        $form = Form::factory()->withTurnstile()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com'],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertStatus(422);
    }

    public function test_turnstile_verification_fails_on_bad_token(): void
    {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]),
        ]);

        $form = Form::factory()->withTurnstile()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com'],
            'cf-turnstile-response' => 'XXXX.DUMMY.TOKEN.XXXX',
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('form_submissions', 0);
    }

    public function test_turnstile_verification_passes_on_good_token(): void
    {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $form = Form::factory()->withTurnstile()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com'],
            'cf-turnstile-response' => 'XXXX.DUMMY.TOKEN.XXXX',
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertCreated();
    }

    public function test_turnstile_not_invoked_when_disabled(): void
    {
        Http::fake();

        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
            'captcha_provider' => 'none',
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com'],
        ], ['X-Form-Key' => $form->api_key])->assertCreated();

        Http::assertNothingSent();
    }

    public function test_spam_rejection_message_does_not_leak_which_check_failed(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
            'honeypot_field' => 'website',
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com', 'website' => 'spam'],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertStatus(422);
        $this->assertSame('Submission could not be processed.', $response->json('message'));
        // No per-field errors leak form structure to bots.
        $this->assertSame([], $response->json('errors'));
    }
}
