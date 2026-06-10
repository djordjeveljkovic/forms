<?php

namespace Tests\Feature\Api;

use App\Enums\FormFieldType;
use App\Models\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoDiscoverFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_submission_auto_creates_fields_when_form_has_none(): void
    {
        $form = Form::factory()->create([
            'auto_discover_fields' => true,
            'send_email' => false,
        ]);

        $this->assertSame(0, $form->fields()->count());

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => [
                'full_name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'phone' => '+1 555 1234',
                'message' => 'This is a long message that is well over 200 characters in length so it will be detected as a textarea by the FormFieldDiscoverer service based on its length being greater than 200 characters which is the threshold we configured for detecting text vs textarea type.',
            ],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertCreated();
        $this->assertSame(4, $form->fields()->count());

        $byName = $form->fields()->get()->keyBy('name');
        $this->assertSame(FormFieldType::Text, $byName['full_name']->typeEnum());
        $this->assertSame(FormFieldType::Email, $byName['email']->typeEnum());
        $this->assertSame(FormFieldType::Tel, $byName['phone']->typeEnum());
        $this->assertSame(FormFieldType::Textarea, $byName['message']->typeEnum());
    }

    public function test_subsequent_submissions_validate_against_discovered_fields(): void
    {
        $form = Form::factory()->create([
            'auto_discover_fields' => true,
            'send_email' => false,
        ]);

        // First submission: creates fields
        $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com'],
        ], ['X-Form-Key' => $form->api_key])->assertCreated();

        // Second submission: email is now a known field of type email and a
        // value of the wrong shape is rejected by the per-field validator.
        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'not-a-valid-email'],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['data.email']);
    }

    public function test_auto_discovery_does_not_run_when_disabled(): void
    {
        $form = Form::factory()->noAutoDiscover()->create([
            'send_email' => false,
        ]);

        $this->assertSame(0, $form->fields()->count());

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['email' => 'jane@example.com'],
        ], ['X-Form-Key' => $form->api_key]);

        // The form is still created and the submission stored, but no fields are auto-created.
        $response->assertCreated();
        $this->assertSame(0, $form->fresh()->fields()->count());
    }

    public function test_auto_discovery_skips_forms_that_already_have_fields(): void
    {
        $form = Form::factory()->create([
            'auto_discover_fields' => true,
            'send_email' => false,
        ]);
        $form->fields()->create([
            'name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'position' => 0,
        ]);

        $this->assertSame(1, $form->fields()->count());

        $response = $this->postJson("/api/forms/{$form->slug}", [
            'data' => [
                'name' => 'Jane',
                'email' => 'jane@example.com',
            ],
        ], ['X-Form-Key' => $form->api_key]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['data']);
    }

    public function test_auto_discovery_infers_types_from_value_for_unknown_keys(): void
    {
        $form = Form::factory()->create([
            'auto_discover_fields' => true,
            'send_email' => false,
        ]);

        $this->postJson("/api/forms/{$form->slug}", [
            'data' => [
                'foo' => '42',                  // numeric string -> number
                'bar' => 'not-an-email',        // text
                'baz' => 'jane@example.com',    // email
                'qux' => 'https://example.com', // url
            ],
        ], ['X-Form-Key' => $form->api_key])->assertCreated();

        $byName = $form->fresh()->fields()->get()->keyBy('name');
        $this->assertSame(FormFieldType::Number, $byName['foo']->typeEnum());
        $this->assertSame(FormFieldType::Text, $byName['bar']->typeEnum());
        $this->assertSame(FormFieldType::Email, $byName['baz']->typeEnum());
        $this->assertSame(FormFieldType::Url, $byName['qux']->typeEnum());
    }

    public function test_auto_discovery_humanises_field_labels(): void
    {
        $form = Form::factory()->create([
            'auto_discover_fields' => true,
            'send_email' => false,
        ]);

        $this->postJson("/api/forms/{$form->slug}", [
            'data' => ['full_name' => 'Jane', 'email_address' => 'jane@example.com'],
        ], ['X-Form-Key' => $form->api_key])->assertCreated();

        $byName = $form->fresh()->fields()->get()->keyBy('name');
        $this->assertSame('Full Name', $byName['full_name']->label);
        $this->assertSame('Email Address', $byName['email_address']->label);
    }

    public function test_schema_endpoint_includes_auto_discover_flag(): void
    {
        $form = Form::factory()->create(['auto_discover_fields' => true]);

        $this->getJson("/api/forms/{$form->slug}", [
            'X-Form-Key' => $form->api_key,
        ])->assertJsonPath('form.auto_discover_fields', true);
    }
}
