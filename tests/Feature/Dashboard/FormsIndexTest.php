<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\FormsIndex;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FormsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_view_forms_index(): void
    {
        $this->get(route('dashboard.forms.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_view_forms_index(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard.forms.index'))->assertOk();
    }

    public function test_forms_listing_shows_existing_forms(): void
    {
        $this->actingAs(User::factory()->create());

        Form::factory()->create(['name' => 'Contact Form']);
        Form::factory()->create(['name' => 'Newsletter Form']);

        Livewire::test(FormsIndex::class)
            ->assertSee('Contact Form')
            ->assertSee('Newsletter Form');
    }

    public function test_search_filter_narrows_results(): void
    {
        $this->actingAs(User::factory()->create());

        Form::factory()->create(['name' => 'Contact Form']);
        Form::factory()->create(['name' => 'Newsletter Form']);

        Livewire::test(FormsIndex::class)
            ->set('search', 'contact')
            ->assertSee('Contact Form')
            ->assertDontSee('Newsletter Form');
    }

    public function test_archived_forms_can_be_filtered(): void
    {
        $this->actingAs(User::factory()->create());

        Form::factory()->create(['name' => 'Active Form']);
        Form::factory()->archived()->create(['name' => 'Archived Form']);

        Livewire::test(FormsIndex::class)
            ->set('statusFilter', 'archived')
            ->assertSee('Archived Form')
            ->assertDontSee('Active Form');
    }

    public function test_archive_marks_form_as_archived(): void
    {
        $this->actingAs(User::factory()->create());

        $form = Form::factory()->create();

        Livewire::test(FormsIndex::class)
            ->call('archive', $form->id);

        $this->assertTrue($form->fresh()->is_archived);
        $this->assertDatabaseHas('audit_logs', ['action' => 'form.archived']);
    }

    public function test_restore_unarchives_form(): void
    {
        $this->actingAs(User::factory()->create());

        $form = Form::factory()->archived()->create();

        Livewire::test(FormsIndex::class)
            ->call('restore', $form->id);

        $this->assertFalse($form->fresh()->is_archived);
        $this->assertDatabaseHas('audit_logs', ['action' => 'form.restored']);
    }

    public function test_api_key_can_be_regenerated(): void
    {
        $this->actingAs(User::factory()->create());

        $form = Form::factory()->create();
        $oldKey = $form->api_key;

        Livewire::test(FormsIndex::class)
            ->call('regenerateApiKey', $form->id);

        $this->assertNotSame($oldKey, $form->fresh()->api_key);
        $this->assertDatabaseHas('audit_logs', ['action' => 'form.api_key.regenerated']);
    }
}
