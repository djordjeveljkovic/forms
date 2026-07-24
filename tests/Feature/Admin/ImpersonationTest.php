<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => User::ROLE_ADMIN, 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => User::ROLE_USER, 'guard_name' => 'web']);
    }

    public function test_admin_can_impersonate_other_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.impersonate.start', $user))
            ->assertRedirect(route('dashboard.index'));

        $this->assertEquals($user->id, auth()->id());
        $this->assertEquals($admin->id, session('impersonator_id'));
    }

    public function test_admin_cannot_impersonate_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.impersonate.start', $admin))
            ->assertStatus(400);
    }

    public function test_non_admin_cannot_impersonate(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $this->actingAs($user1)
            ->post(route('admin.users.impersonate.start', $user2))
            ->assertForbidden();
    }

    public function test_admin_can_stop_impersonation(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.impersonate.start', $user))
            ->assertRedirect(route('dashboard.index'));

        $this->assertEquals($user->id, auth()->id());

        $this->post(route('admin.users.impersonate.stop'))
            ->assertRedirect(route('admin.users.index'));

        $this->assertEquals($admin->id, auth()->id());
        $this->assertNull(session('impersonator_id'));
    }

    public function test_stop_impersonation_fails_when_not_impersonating(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.impersonate.stop'))
            ->assertStatus(400);
    }

    public function test_impersonation_creates_audit_logs(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.impersonate.start', $user));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.impersonation.started',
        ]);

        $this->post(route('admin.users.impersonate.stop'));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.impersonation.stopped',
        ]);
    }

    public function test_audit_log_records_impersonator_id_when_acting_as_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        // Start impersonation
        $this->actingAs($admin)
            ->post(route('admin.users.impersonate.start', $user));

        // Now write an audit log while acting as the user (simulated by
        // posting any action that creates an audit log with the
        // current user context).
        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'impersonated.action',
            'metadata' => ['some_key' => 'some_value'],
            'ip_address' => '127.0.0.1',
        ]);

        $log = AuditLog::query()->where('action', 'impersonated.action')->first();
        $this->assertNotNull($log);
        $this->assertEquals($admin->id, $log->metadata['impersonator_id'] ?? null);
    }
}
