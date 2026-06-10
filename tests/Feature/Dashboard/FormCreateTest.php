<?php

namespace Tests\Feature\Dashboard;

use App\Enums\FormFieldType;
use App\Livewire\Dashboard\FormCreate;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FormCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_is_displayed_for_authenticated_users(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard.forms.create'))->assertOk();
    }

    public function test_form_can_be_created_with_fields(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(FormCreate::class)
            ->set('name', 'Contact form')
            ->set('description', 'A simple contact form.')
            ->set('recipientEmails.0', 'admin@example.com')
            ->set('subjectTemplate', 'New submission for :form_name')
            ->set('storeSubmissions', true)
            ->set('sendEmail', true)
            ->set('successMessage', 'Thank you!')
            ->set('fields.0.name', 'full_name')
            ->set('fields.0.label', 'Full name')
            ->set('fields.0.type', FormFieldType::Text->value)
            ->set('fields.0.required', true)
            ->set('fields.1.name', 'email')
            ->set('fields.1.label', 'Email address')
            ->set('fields.1.type', FormFieldType::Email->value)
            ->set('fields.1.required', true)
            ->call('save')
            ->assertHasNoErrors();

        $form = Form::query()->where('name', 'Contact form')->firstOrFail();

        $this->assertSame('contact-form', $form->slug);
        $this->assertSame(['admin@example.com'], $form->recipient_emails);
        $this->assertCount(2, $form->fields);
        $this->assertSame('full_name', $form->fields[0]->name);
        $this->assertSame('email', $form->fields[1]->name);
        $this->assertTrue($form->fields[0]->required);
        $this->assertSame(0, $form->fields[0]->position);
        $this->assertSame(1, $form->fields[1]->position);
    }

    public function test_form_create_validates_required_fields(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(FormCreate::class)
            ->set('name', '')
            ->set('recipientEmails', [''])
            ->set('subjectTemplate', '')
            ->set('successMessage', '')
            ->call('save')
            ->assertHasErrors(['name', 'subjectTemplate', 'successMessage']);
    }

    public function test_recipient_emails_must_be_valid(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(FormCreate::class)
            ->set('name', 'Bad')
            ->set('recipientEmails.0', 'not-an-email')
            ->set('subjectTemplate', 'Subject')
            ->set('successMessage', 'Thank you.')
            ->call('save')
            ->assertHasErrors(['recipientEmails.0']);
    }

    public function test_add_recipient_creates_empty_field(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(FormCreate::class)
            ->assertSet('recipientEmails', [''])
            ->call('addRecipient')
            ->assertSet('recipientEmails', ['', '']);
    }

    public function test_remove_recipient_drops_field(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(FormCreate::class)
            ->set('recipientEmails', ['a@example.com', 'b@example.com'])
            ->call('removeRecipient', 1)
            ->assertSet('recipientEmails', ['a@example.com']);
    }

    public function test_remove_last_recipient_resets_to_empty_field(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(FormCreate::class)
            ->set('recipientEmails', ['only@example.com'])
            ->call('removeRecipient', 0)
            ->assertSet('recipientEmails', ['']);
    }

    public function test_add_field_creates_empty_row(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(FormCreate::class);
        $this->assertCount(1, $component->get('fields'));

        $component->call('addField');
        $this->assertCount(2, $component->get('fields'));
    }

    public function test_remove_field_drops_row(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(FormCreate::class)
            ->set('fields.0.name', 'first')
            ->set('fields.0.label', 'First')
            ->set('fields.1.name', 'second')
            ->set('fields.1.label', 'Second')
            ->call('removeField', 0);

        $fields = $component->get('fields');
        $this->assertCount(1, $fields);
        $this->assertSame('second', $fields[0]['name']);
        $this->assertSame('Second', $fields[0]['label']);
    }

    public function test_move_field_swaps_with_neighbour(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(FormCreate::class)
            ->set('fields.0.name', 'a')
            ->set('fields.0.label', 'A')
            ->set('fields.1.name', 'b')
            ->set('fields.1.label', 'B')
            ->call('moveField', 0, 'down')
            ->assertSet('fields.0.name', 'b')
            ->assertSet('fields.1.name', 'a');
    }

    public function test_form_create_rejects_duplicate_field_keys(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(FormCreate::class)
            ->set('name', 'Dup')
            ->set('recipientEmails.0', 'admin@example.com')
            ->set('subjectTemplate', 'Subject')
            ->set('successMessage', 'Thank you.')
            ->set('fields.0.name', 'email')
            ->set('fields.0.label', 'Email')
            ->set('fields.0.type', FormFieldType::Email->value)
            ->set('fields.1.name', 'email')
            ->set('fields.1.label', 'Email again')
            ->set('fields.1.type', FormFieldType::Email->value)
            ->call('save')
            ->assertHasErrors(['fields']);
    }

    public function test_form_create_rejects_invalid_field_key(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(FormCreate::class)
            ->set('name', 'Bad key')
            ->set('recipientEmails.0', 'admin@example.com')
            ->set('subjectTemplate', 'Subject')
            ->set('successMessage', 'Thank you.')
            ->set('fields.0.name', '1bad-key')
            ->set('fields.0.label', 'Bad')
            ->set('fields.0.type', FormFieldType::Text->value)
            ->call('save')
            ->assertHasErrors(['fields']);
    }
}
