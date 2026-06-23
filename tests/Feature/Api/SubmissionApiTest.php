<?php

namespace Tests\Feature\Api;

use App\Enums\EmailJobStatus;
use App\Enums\SubmissionStatus;
use App\Jobs\ProcessFormSubmissionEmail;
use App\Models\EmailJob;
use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SubmissionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_endpoint_creates_submission_and_queues_jobs(): void
    {
        Queue::fake();

        $form = Form::factory()->create([
            'recipient_emails' => ['admin@example.com', 'team@example.com'],
            'send_email' => true,
            'store_submissions' => true,
        ]);

        $payload = [
            'data' => [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'message' => 'Hello there.',
            ],
        ];

        $response = $this->postJson("/api/forms/{$form->slug}", $payload, [
            'X-Form-Key' => $form->api_key,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('message', $form->success_message);
        $response->assertJsonPath('submission.data.name', 'Jane Doe');

        $this->assertDatabaseHas('form_submissions', [
            'form_id' => $form->id,
            'status' => SubmissionStatus::Received->value,
        ]);

        $this->assertSame(2, EmailJob::query()->where('status', EmailJobStatus::Pending->value)->count());
        Queue::assertPushed(ProcessFormSubmissionEmail::class, 2);
    }

    public function test_submission_endpoint_rejects_request_with_invalid_api_key(): void
    {
        $form = Form::factory()->create();

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['name' => 'Jane'],
        ], [
            'X-Form-Key' => 'not-the-right-key',
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseCount('form_submissions', 0);
    }

    public function test_submission_endpoint_rejects_request_without_api_key(): void
    {
        $form = Form::factory()->create();

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['name' => 'Jane'],
        ]);

        $response->assertStatus(401);
    }

    public function test_submission_endpoint_rejects_archived_forms(): void
    {
        $form = Form::factory()->archived()->create();

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['name' => 'Jane'],
        ], [
            'X-Form-Key' => $form->api_key,
        ]);

        $response->assertStatus(410);
    }

    public function test_submission_endpoint_supports_query_string_api_key(): void
    {
        Queue::fake();

        $form = Form::factory()->create();

        $response = $this->postJson("/api/forms/{$form->slug}?api_key={$form->api_key}", [
            'data' => ['name' => 'Jane'],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('form_submissions', [
            'form_id' => $form->id,
        ]);
    }

    public function test_submission_endpoint_captures_ip_and_user_agent(): void
    {
        Queue::fake();

        $form = Form::factory()->create();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->withHeaders([
                'User-Agent' => 'AcmeBot/1.0',
                'Referer' => 'https://example.com/landing',
                'X-Form-Key' => $form->api_key,
            ])
            ->postJson("/api/forms/{$form->slug}", [
                'data' => ['name' => 'Jane'],
            ]);

        $response->assertCreated();

        $submission = FormSubmission::query()->firstOrFail();
        $this->assertSame('203.0.113.5', $submission->ip_address);
        $this->assertSame('AcmeBot/1.0', $submission->user_agent);
        $this->assertSame('https://example.com/landing', $submission->referer);
    }

    public function test_health_endpoint_returns_ok(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_schema_endpoint_returns_form_metadata_and_field_schema(): void
    {
        $form = Form::factory()->create(['name' => 'Contact']);
        $form->fields()->create([
            'name' => 'email',
            'label' => 'Email',
            'type' => 'email',
            'required' => true,
            'position' => 0,
        ]);

        $response = $this->getJson("/api/forms/{$form->slug}", [
            'X-Form-Key' => $form->api_key,
        ]);

        $response->assertOk();
        $response->assertJsonPath('form.name', 'Contact');
        $response->assertJsonCount(1, 'fields');
        $response->assertJsonPath('fields.0.name', 'email');
        $response->assertJsonPath('fields.0.type', 'email');
        $response->assertJsonPath('fields.0.required', true);
    }

    public function test_submission_validates_against_configured_fields(): void
    {
        $form = Form::factory()->create();
        $form->fields()->createMany([
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0],
            ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => false, 'position' => 1],
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'not-an-email', 'phone' => '555-1234'],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['data.email']);
    }

    public function test_submission_requires_all_required_fields(): void
    {
        $form = Form::factory()->create();
        $form->fields()->createMany([
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'position' => 1],
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com'],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['data.name']);
    }

    public function test_submission_allows_optional_fields_to_be_omitted(): void
    {
        Queue::fake();

        $form = Form::factory()->create();
        $form->fields()->createMany([
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0],
            ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => false, 'position' => 1],
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com'],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertCreated();
        $this->assertDatabaseHas('form_submissions', [
            'form_id' => $form->id,
        ]);
    }

    public function test_submission_rejects_unknown_fields(): void
    {
        $form = Form::factory()->create();
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => [
                'email' => 'jane@example.com',
                'sneaky' => 'extra value',
            ],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['data']);
    }

    public function test_submission_enforces_type_specific_validation(): void
    {
        $form = Form::factory()->create();
        $form->fields()->create([
            'name' => 'age', 'label' => 'Age', 'type' => 'number', 'required' => true, 'position' => 0,
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['age' => 'not-a-number'],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['data.age']);
    }

    public function test_submission_validates_select_options(): void
    {
        $form = Form::factory()->create();
        $form->fields()->create([
            'name' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => true, 'position' => 0,
            'options' => ['free', 'pro', 'team'],
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['plan' => 'enterprise'],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['data.plan']);
    }

    public function test_submission_checkbox_error_uses_field_label(): void
    {
        $form = Form::factory()->create();
        $form->fields()->create([
            'name' => 'tags', 'label' => 'Tags', 'type' => 'checkbox', 'required' => false, 'position' => 0,
            'options' => ['red', 'blue'],
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['tags' => ['purple']],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['data.tags.0']);
        // The error message should reference the human label, not the
        // internal data path.
        $errorMessage = (string) collect($response->json('errors'))->flatten()->first();
        $this->assertStringContainsString('Tags', $errorMessage);
    }

    public function test_submission_persists_only_known_fields(): void
    {
        Queue::fake();

        $form = Form::factory()->create();
        $form->fields()->create([
            'name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'position' => 0,
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['name' => 'Jane'],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertCreated();

        $submission = FormSubmission::query()->firstOrFail();
        $this->assertSame(['name' => 'Jane'], $submission->submission_data);
    }

    public function test_submission_sends_email_when_storage_disabled_but_email_enabled(): void
    {
        Queue::fake();

        $form = Form::factory()->create([
            'store_submissions' => false,
            'send_email' => true,
            'recipient_emails' => ['admin@example.com', 'team@example.com'],
        ]);
        $form->fields()->create([
            'name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'position' => 0,
        ]);

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['name' => 'Jane'],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertCreated();
        // The submission row is created to back the email jobs even
        // though the form opted out of long-term storage. Otherwise the
        // email would silently disappear because the email_jobs table
        // has a FK to form_submissions.
        $this->assertSame(1, FormSubmission::query()->count());
        $this->assertSame(2, EmailJob::query()->count());
        Queue::assertPushed(ProcessFormSubmissionEmail::class, 2);
    }

    public function test_submission_resolves_subject_template_in_email_jobs(): void
    {
        Queue::fake();

        $form = Form::factory()->create([
            'subject_template' => 'New :form_name submission for :form_slug',
            'send_email' => true,
            'recipient_emails' => ['admin@example.com'],
        ]);
        $form->fields()->create([
            'name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'position' => 0,
        ]);

        $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['name' => 'Jane'],
        ], ['X-Form-Key' => $form->api_key])->assertCreated();

        $job = EmailJob::query()->firstOrFail();
        $this->assertSame('New '.$form->name.' submission for '.$form->slug, $job->subject);
    }
}
