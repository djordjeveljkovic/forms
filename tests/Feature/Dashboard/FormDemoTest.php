<?php

namespace Tests\Feature\Dashboard;

use App\Jobs\ProcessFormSubmissionEmail;
use App\Livewire\Dashboard\FormDemo;
use App\Models\EmailJob;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class FormDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_page_is_displayed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        $this->get(route('dashboard.forms.demo', $form))->assertOk();
    }

    public function test_demo_page_renders_field_inputs(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        $form->fields()->createMany([
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0],
            ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => false, 'position' => 1],
        ]);

        Livewire::test(FormDemo::class, ['form' => $form])
            ->assertSet('values.email', '')
            ->assertSet('values.message', '')
            ->assertSee('Email')
            ->assertSee('Message');
    }

    public function test_demo_can_switch_tabs(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();

        Livewire::test(FormDemo::class, ['form' => $form])
            ->assertSet('activeTab', 'test')
            ->call('setTab', 'code')
            ->assertSet('activeTab', 'code')
            ->call('setTab', 'test')
            ->assertSet('activeTab', 'test');
    }

    public function test_demo_ignores_unknown_tabs(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();

        Livewire::test(FormDemo::class, ['form' => $form])
            ->call('setTab', 'bogus')
            ->assertSet('activeTab', 'test');
    }

    public function test_demo_html_snippet_contains_inputs_and_action(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create(['slug' => 'contact']);
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $component = Livewire::test(FormDemo::class, ['form' => $form]);
        $snippet = $component->htmlSnippet;

        $this->assertStringContainsString('action="'.url('/api/forms/contact').'?api_key='.$form->api_key.'"', $snippet);
        $this->assertStringContainsString('name="email"', $snippet);
        $this->assertStringContainsString('type="email"', $snippet);
        $this->assertStringContainsString('required', $snippet);
    }

    public function test_demo_js_snippet_contains_endpoint_and_field_names(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        $form->fields()->createMany([
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0],
            ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => false, 'position' => 1],
        ]);

        $component = Livewire::test(FormDemo::class, ['form' => $form]);
        $snippet = $component->jsSnippet;

        $this->assertStringContainsString(url('/api/forms/'.$form->slug), $snippet);
        $this->assertStringContainsString("'X-Form-Key': '".$form->api_key."'", $snippet);
        $this->assertStringContainsString("formData.get('email')", $snippet);
        $this->assertStringContainsString("formData.get('message')", $snippet);
    }

    public function test_demo_html_snippet_renders_radio_field(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        $form->fields()->create([
            'name' => 'choice', 'label' => 'Pick one', 'type' => 'radio', 'required' => true, 'position' => 0,
            'options' => ['Yes', 'No'],
        ]);

        $component = Livewire::test(FormDemo::class, ['form' => $form]);
        $snippet = $component->htmlSnippet;

        $this->assertStringContainsString('type="radio"', $snippet);
        $this->assertStringContainsString('value="Yes"', $snippet);
        $this->assertStringContainsString('value="No"', $snippet);
    }

    public function test_demo_html_snippet_renders_checkbox_field_with_array_name(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        $form->fields()->create([
            'name' => 'tags', 'label' => 'Tags', 'type' => 'checkbox', 'required' => false, 'position' => 0,
            'options' => ['red', 'blue'],
        ]);

        $component = Livewire::test(FormDemo::class, ['form' => $form]);
        $snippet = $component->htmlSnippet;

        $this->assertStringContainsString('name="tags[]"', $snippet);
        $this->assertStringContainsString('value="red"', $snippet);
    }

    public function test_demo_submit_persists_submission_and_queues_jobs(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create(['send_email' => true]);
        $form->fields()->createMany([
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0],
            ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'position' => 1],
        ]);

        Livewire::test(FormDemo::class, ['form' => $form])
            ->set('values.email', 'jane@example.com')
            ->set('values.message', 'Hello world')
            ->call('submit')
            ->assertSet('result.status', 201)
            ->assertSet('result.body.message', $form->success_message);

        $this->assertDatabaseHas('form_submissions', [
            'form_id' => $form->id,
            'submission_data->email' => 'jane@example.com',
        ]);
        $this->assertSame(1, EmailJob::query()->count());
        Queue::assertPushed(ProcessFormSubmissionEmail::class, 1);
    }

    public function test_demo_submit_shows_validation_errors(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        $form->fields()->create([
            'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'position' => 0,
        ]);

        $component = Livewire::test(FormDemo::class, ['form' => $form])
            ->call('submit');

        $result = $component->get('result');
        $this->assertNotNull($result);
        $this->assertSame(422, $result['status']);
        $this->assertSame('The Email field is required.', $result['body']['errors']['data.email'][0]);
    }

    public function test_demo_reset_form_clears_state(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create(['send_email' => false]);
        $form->fields()->create([
            'name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'position' => 0,
        ]);

        $component = Livewire::test(FormDemo::class, ['form' => $form])
            ->set('values.name', 'Jane')
            ->call('submit')
            ->call('resetForm');

        $values = $component->get('values');
        $this->assertSame('', $values['name']);
        $this->assertNull($component->get('result'));
    }

    public function test_demo_submit_does_not_queue_when_send_email_disabled(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create(['send_email' => false, 'store_submissions' => true]);
        $form->fields()->create([
            'name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'position' => 0,
        ]);

        Livewire::test(FormDemo::class, ['form' => $form])
            ->set('values.name', 'Jane')
            ->call('submit')
            ->assertSet('result.status', 201);

        $this->assertSame(1, FormSubmission::query()->count());
        $this->assertSame(0, EmailJob::query()->count());
        Queue::assertNotPushed(ProcessFormSubmissionEmail::class);
    }

    public function test_demo_submit_rejects_archived_form(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->archived()->create();
        $form->fields()->create([
            'name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'position' => 0,
        ]);

        $component = Livewire::test(FormDemo::class, ['form' => $form])
            ->set('values.name', 'Jane')
            ->call('submit');

        $result = $component->get('result');
        $this->assertSame(410, $result['status']);
        $this->assertStringContainsString('no longer accepting', $result['body']['message']);
    }
}
