<?php

namespace Tests\Feature\Admin;

use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PolicyAdminBypassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => User::ROLE_ADMIN, 'guard_name' => 'web']);
    }

    public function test_admin_can_view_other_users_forms(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $form = Form::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($admin->can('view', $form));
        $this->assertTrue($admin->can('update', $form));
        $this->assertTrue($admin->can('delete', $form));
    }

    public function test_non_admin_cannot_view_other_users_forms(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $form = Form::factory()->create(['user_id' => $user2->id]);

        $this->assertFalse($user1->can('view', $form));
        $this->assertFalse($user1->can('update', $form));
    }

    public function test_owner_can_view_own_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->can('view', $form));
    }
}
