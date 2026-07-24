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
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('dashboard.forms.index'))->assertOk();
    }

    public function test_forms_listing_shows_existing_forms(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Form::factory()->ownedBy($user)->create(['name' => 'Contact Form']);
        Form::factory()->ownedBy($user)->create(['name' => 'Newsletter Form']);

        Livewire::test(FormsIndex::class)
            ->assertSee('Contact Form')
            ->assertSee('Newsletter Form');
    }

    public function test_search_filter_narrows_results(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Form::factory()->ownedBy($user)->create(['name' => 'Contact Form']);
        Form::factory()->ownedBy($user)->create(['name' => 'Newsletter Form']);

        Livewire::test(FormsIndex::class)
            ->set('search', 'contact')
            ->assertSee('Contact Form')
            ->assertDontSee('Newsletter Form');
    }

    public function test_archived_forms_can_be_filtered(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Form::factory()->ownedBy($user)->create(['name' => 'Active Form']);
        Form::factory()->ownedBy($user)->archived()->create(['name' => 'Archived Form']);

        Livewire::test(FormsIndex::class)
            ->set('statusFilter', 'archived')
            ->assertSee('Archived Form')
            ->assertDontSee('Active Form');
    }

    public function test_archive_marks_form_as_archived(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();

        Livewire::test(FormsIndex::class)
            ->call('archive', $form->id);

        $this->assertTrue($form->fresh()->is_archived);
        $this->assertDatabaseHas('audit_logs', ['action' => 'form.archived']);
    }

    public function test_restore_unarchives_form(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->archived()->create();

        Livewire::test(FormsIndex::class)
            ->call('restore', $form->id);

        $this->assertFalse($form->fresh()->is_archived);
        $this->assertDatabaseHas('audit_logs', ['action' => 'form.restored']);
    }

    public function test_api_key_can_be_regenerated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->ownedBy($user)->create();
        $oldKey = $form->api_key;

        Livewire::test(FormsIndex::class)
            ->call('regenerateApiKey', $form->id);

        $this->assertNotSame($oldKey, $form->fresh()->api_key);
        $this->assertDatabaseHas('audit_logs', ['action' => 'form.api_key.regenerated']);
    }
}
