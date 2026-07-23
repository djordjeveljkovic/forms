<?php

namespace Tests\Feature\Api;

use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the "plain HTML form" submission path:
 *  - form-encoded (application/x-www-form-urlencoded) bodies
 *  - fields at the top level (no `data` wrapper)
 *  - browser-style redirect after success
 *  - error redirect with field errors
 */
class SubmissionApiFormEncodedTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_encoded_submission_with_top_level_fields(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'store_submissions' => true,
            'min_submission_seconds' => 0,
        ]);
        $form->fields()->createMany([
            ['name' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'position' => 0],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 1],
            ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'position' => 2],
        ]);

        $response = $this->post("/api/forms/{$form->slug}?api_key={$form->api_key}", [
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'message' => 'Hello from a plain HTML form.',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('form_submissions', [
            'form_id' => $form->id,
            'submission_data->full_name' => 'Jane Doe',
            'submission_data->email' => 'jane@example.com',
        ]);
    }

    public function test_json_data_wrapper_still_works_for_backward_compatibility(): void
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
        $this->assertDatabaseHas('form_submissions', [
            'form_id' => $form->id,
            'submission_data->email' => 'jane@example.com',
        ]);
    }

    public function test_redirects_to_form_configured_url_after_success(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
            'success_redirect_url' => 'https://example.com/thank-you',
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->post("/api/forms/{$form->slug}?api_key={$form->api_key}", [
            'email' => 'jane@example.com',
        ]);

        $submission = FormSubmission::query()->firstOrFail();
        $response->assertRedirectContains('https://example.com/thank-you');
        $response->assertRedirectContains('submission_id='.$submission->id);
    }

    public function test_per_submission_redirect_overrides_form_default(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
            'success_redirect_url' => 'https://example.com/default',
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->post("/api/forms/{$form->slug}?api_key={$form->api_key}", [
            'email' => 'jane@example.com',
            '_redirect' => 'https://elsewhere.test/specific',
        ]);

        $response->assertRedirect('https://elsewhere.test/specific?submission_id=1');
    }

    public function test_query_string_return_url_overrides_form_default(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
            'success_redirect_url' => 'https://example.com/default',
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->post(
            "/api/forms/{$form->slug}?api_key={$form->api_key}&return_url=https://query.test/win",
            ['email' => 'jane@example.com'],
        );

        $response->assertRedirectContains('https://query.test/win');
    }

    public function test_validation_errors_redirect_back_with_field_errors(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
            'success_redirect_url' => 'https://example.com/oops',
        ]);
        $form->fields()->createMany([
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'position' => 1],
        ]);

        // Missing required `name` field
        $response = $this->post("/api/forms/{$form->slug}?api_key={$form->api_key}", [
            'email' => 'not-an-email',
        ]);

        $response->assertRedirectContains('https://example.com/oops');
        $response->assertRedirectContains('status=invalid');
        // Errors JSON-encoded into query string so the landing page can parse them.
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $query = parse_url($location, PHP_URL_QUERY);
        $this->assertNotNull($query);
        parse_str($query, $params);
        $this->assertArrayHasKey('errors', $params);
        $errors = json_decode($params['errors'], true);
        $this->assertArrayHasKey('data.email', $errors);
        $this->assertArrayHasKey('data.name', $errors);
    }

    public function test_json_client_still_gets_json_response(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
            'success_redirect_url' => 'https://example.com/should-not-redirect',
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}?api_key={$form->api_key}", [
            'email' => 'jane@example.com',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('message', $form->success_message);
    }

    public function test_no_redirect_configured_returns_json(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
            'success_redirect_url' => null,
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->post("/api/forms/{$form->slug}?api_key={$form->api_key}", [
            'email' => 'jane@example.com',
        ]);

        $response->assertCreated();
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('content-type'));
    }

    public function test_form_submission_strips_internal_control_fields(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
            'honeypot_field' => 'website',
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $this->postJson("/api/forms/{$form->slug}", [
            'data' => [
                'email' => 'jane@example.com',
                '_redirect' => 'https://example.com/x',
                '_timestamp' => '12345',
                '_honeypot' => 'should-be-stripped',
                'cf-turnstile-response' => 'fake-token',
            ],
        ], ['X-Form-Key' => $form->api_key])->assertCreated();

        $submission = FormSubmission::query()->firstOrFail();
        $this->assertSame(['email' => 'jane@example.com'], $submission->submission_data);
    }

    public function test_unknown_fields_at_top_level_are_rejected(): void
    {
        $form = Form::factory()->create([
            'send_email' => false,
            'min_submission_seconds' => 0,
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->post("/api/forms/{$form->slug}?api_key={$form->api_key}", [
            'email' => 'jane@example.com',
            'sneaky' => 'extra value',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('form_submissions', 0);
    }
}
