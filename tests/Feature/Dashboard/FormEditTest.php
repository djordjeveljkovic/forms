<?php

namespace Tests\Feature\Dashboard;

use App\Enums\FormFieldType;
use App\Livewire\Dashboard\FormEdit;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FormEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_is_displayed_for_existing_form(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        $this->get(route('dashboard.forms.edit', $form))->assertOk();
    }

    public function test_form_can_be_updated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create([
            'name' => 'Old name',
            'recipient_emails' => ['old@example.com'],
        ]);

        Livewire::test(FormEdit::class, ['form' => $form])
            ->set('name', 'New name')
            ->set('recipientEmails.0', 'new@example.com')
            ->set('subjectTemplate', 'Hello world')
            ->set('successMessage', 'Got it')
            ->call('save')
            ->assertHasNoErrors();

        $form->refresh();
        $this->assertSame('New name', $form->name);
        $this->assertSame(['new@example.com'], $form->recipient_emails);
        $this->assertSame('Hello world', $form->subject_template);
    }

    public function test_api_key_regenerates(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->ownedBy($user)->create();
        $oldKey = $form->api_key;

        Livewire::test(FormEdit::class, ['form' => $form])
            ->call('regenerateApiKey');

        $this->assertNotSame($oldKey, $form->fresh()->api_key);
    }

    public function test_form_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->ownedBy($user)->create();

        Livewire::test(FormEdit::class, ['form' => $form])
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertNull(Form::query()->find($form->id));
    }

    public function test_existing_fields_are_loaded_into_rows(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        $form->fields()->createMany([
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0],
            ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => false, 'position' => 1],
        ]);

        Livewire::test(FormEdit::class, ['form' => $form])
            ->assertSet('fields.0.name', 'email')
            ->assertSet('fields.0.type', 'email')
            ->assertSet('fields.0.required', true)
            ->assertSet('fields.1.name', 'message')
            ->assertSet('fields.1.type', 'textarea')
            ->assertSet('fields.1.required', false);
    }

    public function test_fields_can_be_updated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false, 'position' => 0,
        ]);

        Livewire::test(FormEdit::class, ['form' => $form])
            ->set('fields.0.required', true)
            ->set('fields.0.placeholder', 'you@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $field = $form->fields()->first();
        $this->assertTrue($field->required);
        $this->assertSame('you@example.com', $field->placeholder);
    }

    public function test_fields_can_be_added_via_edit_screen(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->ownedBy($user)->create();

        Livewire::test(FormEdit::class, ['form' => $form])
            ->set('fields.0.name', 'phone')
            ->set('fields.0.label', 'Phone')
            ->set('fields.0.type', FormFieldType::Tel->value)
            ->set('fields.0.required', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $form->fields()->count());
        $this->assertSame('phone', $form->fields()->first()->name);
    }

    public function test_fields_can_be_removed_via_edit_screen(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->ownedBy($user)->create();
        $form->fields()->create([
            'name' => 'old', 'label' => 'Old', 'type' => 'text', 'required' => false, 'position' => 0,
        ]);

        Livewire::test(FormEdit::class, ['form' => $form])
            ->call('removeField', 0)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, $form->fields()->count());
    }
}
