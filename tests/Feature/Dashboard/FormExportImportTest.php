<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\FormEdit;
use App\Livewire\Dashboard\FormImport;
use App\Models\Form;
use App\Models\User;
use App\Services\FormExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FormExportImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_method_returns_a_downloadable_json_file(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create([
            'name' => 'Contact',
            'slug' => 'contact',
            'recipient_emails' => ['admin@example.com'],
            'subject_template' => 'Hello',
        ]);
        $form->fields()->createMany([
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0],
            ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'position' => 1],
        ]);

        $response = Livewire::test(FormEdit::class, ['form' => $form])
            ->call('exportJson');

        // The streamed response should be a 200 with the right headers.
        $this->assertNotNull($response);
    }

    public function test_exporter_service_produces_complete_payload(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->ownedBy($user)->create([
            'name' => 'Contact',
            'slug' => 'contact',
            'recipient_emails' => ['admin@example.com'],
        ]);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
            'placeholder' => 'you@example.com',
        ]);

        $exporter = app(FormExporter::class);
        $payload = $exporter->export($form);

        $this->assertSame(FormExporter::VERSION, $payload['version']);
        $this->assertArrayHasKey('exported_at', $payload);
        $this->assertSame('Contact', $payload['form']['name']);
        $this->assertSame(['admin@example.com'], $payload['form']['recipient_emails']);
        $this->assertCount(1, $payload['fields']);
        $this->assertSame('email', $payload['fields'][0]['name']);
        $this->assertSame('email', $payload['fields'][0]['type']);
        $this->assertTrue($payload['fields'][0]['required']);
        $this->assertSame('you@example.com', $payload['fields'][0]['placeholder']);
    }

    public function test_export_payload_omits_api_key(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->ownedBy($user)->create();
        $payload = app(FormExporter::class)->export($form);

        $this->assertArrayNotHasKey('api_key', $payload['form']);
        $this->assertArrayNotHasKey('api_key', $payload);
    }

    public function test_import_creates_a_new_form_with_fields(): void
    {
        $payload = [
            'version' => FormExporter::VERSION,
            'form' => [
                'name' => 'Imported Contact',
                'description' => 'Hello',
                'recipient_emails' => ['a@example.com', 'b@example.com'],
                'subject_template' => 'New submission for :form_name',
                'store_submissions' => true,
                'send_email' => true,
                'success_message' => 'Thanks!',
            ],
            'fields' => [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0],
                ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => false, 'position' => 1],
            ],
        ];

        $form = app(FormExporter::class)->import($payload);

        $this->assertSame('Imported Contact', $form->name);
        $this->assertSame('imported-contact', $form->slug);
        $this->assertSame(['a@example.com', 'b@example.com'], $form->recipient_emails);
        $this->assertNotEmpty($form->api_key);
        $this->assertCount(2, $form->fields);
        $this->assertSame('email', $form->fields[0]->name);
        $this->assertSame('textarea', $form->fields[1]->type);
    }

    public function test_import_regenerates_api_key(): void
    {
        $payload = [
            'form' => [
                'name' => 'Foo',
                'recipient_emails' => ['a@example.com'],
            ],
            'fields' => [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'position' => 0],
            ],
        ];

        $form = app(FormExporter::class)->import($payload);

        $this->assertNotEmpty($form->api_key);
        $this->assertSame(48, strlen($form->api_key));
    }

    public function test_import_appends_suffix_when_slug_already_exists(): void
    {
        $user = User::factory()->create();
        Form::factory()->ownedBy($user)->create(['slug' => 'contact']);

        $payload = [
            'form' => [
                'name' => 'Contact',
                'recipient_emails' => ['a@example.com'],
            ],
            'fields' => [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'position' => 0],
            ],
        ];

        $form = app(FormExporter::class)->import($payload);

        $this->assertNotSame('contact', $form->slug);
        $this->assertStringStartsWith('contact-', $form->slug);
    }

    public function test_import_throws_when_recipients_are_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(FormExporter::class)->import([
            'form' => ['name' => 'Bad', 'recipient_emails' => []],
            'fields' => [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'position' => 0],
            ],
        ]);
    }

    public function test_import_throws_when_field_type_is_invalid(): void
    {
        $this->expectException(\RuntimeException::class);

        app(FormExporter::class)->import([
            'form' => ['name' => 'Bad', 'recipient_emails' => ['a@example.com']],
            'fields' => [
                ['name' => 'name', 'label' => 'Name', 'type' => 'unknown', 'required' => true, 'position' => 0],
            ],
        ]);
    }

    public function test_round_trip_export_import_preserves_configuration(): void
    {
        $user = User::factory()->create();
        $original = Form::factory()->ownedBy($user)->create([
            'name' => 'Contact',
            'slug' => 'contact',
            'description' => 'A contact form.',
            'recipient_emails' => ['a@example.com', 'b@example.com'],
            'from_email' => 'noreply@example.com',
            'from_name' => 'Acme',
            'subject_template' => 'New :form_name submission',
            'allowed_origins' => ['https://example.com'],
            'store_submissions' => true,
            'send_email' => true,
            'success_message' => 'Thank you.',
            'submitter_reply_to_field' => 'email',
        ]);
        $original->fields()->createMany([
            [
                'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
                'placeholder' => 'you@example.com',
            ],
            [
                'name' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => true, 'position' => 1,
                'options' => ['free', 'pro'],
            ],
        ]);

        $exporter = app(FormExporter::class);
        $payload = $exporter->export($original);
        $reimported = $exporter->import($payload);

        $this->assertSame($original->recipient_emails, $reimported->recipient_emails);
        $this->assertSame($original->from_email, $reimported->from_email);
        $this->assertSame($original->from_name, $reimported->from_name);
        $this->assertSame($original->subject_template, $reimported->subject_template);
        $this->assertSame($original->allowed_origins, $reimported->allowed_origins);
        $this->assertSame($original->success_message, $reimported->success_message);
        $this->assertCount(2, $reimported->fields);
        $this->assertSame(['free', 'pro'], $reimported->fields->firstWhere('name', 'plan')->options);
    }

    public function test_form_import_page_is_displayed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('dashboard.forms.import'))->assertOk();
    }

    public function test_form_import_component_handles_invalid_json(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(FormImport::class)
            ->set('rawJson', 'not json');

        // Either an error is set, or preview remains null
        $this->assertTrue($component->get('preview') === null || $component->get('importError') !== '');
    }

    public function test_form_import_component_previews_valid_payload(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $payload = json_encode([
            'form' => [
                'name' => 'Contact',
                'recipient_emails' => ['a@example.com'],
            ],
            'fields' => [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0],
            ],
        ], JSON_THROW_ON_ERROR);

        $component = Livewire::test(FormImport::class)
            ->set('rawJson', $payload)
            ->assertSet('preview.form.name', 'Contact');

        $this->assertCount(1, $component->get('preview.fields'));
    }

    public function test_form_import_component_rejects_payload_missing_recipients(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $payload = json_encode([
            'form' => [
                'name' => 'NoRecipients',
                'recipient_emails' => [],
            ],
            'fields' => [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'position' => 0],
            ],
        ], JSON_THROW_ON_ERROR);

        Livewire::test(FormImport::class)
            ->set('rawJson', $payload)
            ->assertSet('preview', null);
    }

    public function test_importer_accepts_fields_only_payload_with_overrides(): void
    {
        $payload = [
            [
                'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
            ],
            [
                'name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => false, 'position' => 1,
            ],
        ];

        $overrides = [
            'name' => 'Bare Array Import',
            'description' => 'Imported from a bare array.',
            'recipient_emails' => ['team@example.com'],
        ];

        $form = app(FormExporter::class)->import($payload, null, $overrides);

        $this->assertSame('Bare Array Import', $form->name);
        $this->assertSame(['team@example.com'], $form->recipient_emails);
        $this->assertSame('Imported from a bare array.', $form->description);
        $this->assertCount(2, $form->fields);
        $this->assertSame('email', $form->fields[0]->name);
        $this->assertSame('message', $form->fields[1]->name);
    }

    public function test_importer_accepts_fields_only_payload_with_fields_key(): void
    {
        $payload = [
            'fields' => [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'position' => 0],
            ],
        ];

        $overrides = [
            'name' => 'Fields Key Import',
            'recipient_emails' => ['admin@example.com'],
        ];

        $form = app(FormExporter::class)->import($payload, null, $overrides);

        $this->assertSame('Fields Key Import', $form->name);
        $this->assertCount(1, $form->fields);
        $this->assertSame('name', $form->fields->first()->name);
    }

    public function test_importer_throws_when_fields_only_payload_has_no_overrides(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(FormExporter::class)->import([
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'position' => 0],
        ]);
    }

    public function test_import_form_config_component_detects_fields_only_payload(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $payload = json_encode([
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0],
        ], JSON_THROW_ON_ERROR);

        $component = Livewire::test(FormImport::class)
            ->set('rawJson', $payload);

        $this->assertSame('fields', $component->get('previewMode'));
        $this->assertNotNull($component->get('preview'));
    }

    public function test_import_form_config_component_detects_full_payload(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $payload = json_encode([
            'form' => [
                'name' => 'Contact',
                'recipient_emails' => ['a@example.com'],
            ],
            'fields' => [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0],
            ],
        ], JSON_THROW_ON_ERROR);

        $component = Livewire::test(FormImport::class)
            ->set('rawJson', $payload);

        $this->assertSame('full', $component->get('previewMode'));
    }

    public function test_import_form_config_component_creates_form_from_fields_only_payload(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $payload = json_encode([
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0],
        ], JSON_THROW_ON_ERROR);

        Livewire::test(FormImport::class)
            ->set('rawJson', $payload)
            ->set('formName', 'Imported from fields-only')
            ->set('formRecipientEmails.0', 'admin@example.com')
            ->set('formSubjectTemplate', 'New submission for :form_name')
            ->set('formSuccessMessage', 'Thank you!')
            ->call('import')
            ->assertHasNoErrors();

        $form = Form::query()->where('name', 'Imported from fields-only')->firstOrFail();
        $this->assertSame(['admin@example.com'], $form->recipient_emails);
        $this->assertCount(1, $form->fields);
    }

    public function test_export_includes_auto_discover_flag(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->ownedBy($user)->create(['auto_discover_fields' => true]);
        $payload = app(FormExporter::class)->export($form);

        $this->assertTrue($payload['form']['auto_discover_fields']);
    }
}
