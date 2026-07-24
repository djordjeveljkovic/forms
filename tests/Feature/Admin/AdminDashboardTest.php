<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\AdminDashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_admin_dashboard(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_non_admin_users_get_403_on_admin_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_admin_users_can_visit_admin_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_admin_dashboard_renders_global_kpis(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(3)->create();

        $component = Livewire::actingAs($admin)
            ->test(AdminDashboard::class)
            ->assertSet('range', '30d');

        $this->assertEquals(4, $component->totalUsers);
        $this->assertEquals(1, $component->adminUserCount);
    }
}
