<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\UserCreate;
use App\Livewire\Admin\UserEdit;
use App\Livewire\Admin\UsersIndex;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles are seeded by the seeder; tests need them to exist.
        Role::query()->firstOrCreate(['name' => User::ROLE_ADMIN, 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => User::ROLE_USER, 'guard_name' => 'web']);
    }

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(3)->create();

        $component = Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->assertSet('search', '');

        $this->assertEquals(4, $component->users->total());
    }

    public function test_admin_can_search_users_by_email(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'findme@example.com']);
        User::factory()->create(['email' => 'other@example.com']);

        $component = Livewire::actingAs($admin)
            ->test(UsersIndex::class, ['search' => 'findme']);

        $this->assertEquals(1, $component->users->total());
        $this->assertEquals('findme@example.com', $component->users->first()->email);
    }

    public function test_admin_can_filter_by_role(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create();

        $component = Livewire::actingAs($admin)
            ->test(UsersIndex::class, ['roleFilter' => 'admin']);

        $this->assertEquals(1, $component->users->total());
    }

    public function test_admin_can_create_user(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->free()->create();

        Livewire::actingAs($admin)
            ->test(UserCreate::class)
            ->set('name', 'New Person')
            ->set('email', 'new@example.com')
            ->set('password', 'password123')
            ->set('isAdmin', false)
            ->set('planId', $plan->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.users.show', 2));

        $this->assertDatabaseHas('users', [
            'email' => 'new@example.com',
            'name' => 'New Person',
        ]);

        $newUser = User::query()->where('email', 'new@example.com')->first();
        $this->assertTrue($newUser->hasRole(User::ROLE_USER));
        $this->assertNotNull($newUser->activeSubscription);
        $this->assertEquals($plan->id, $newUser->activeSubscription->plan_id);
    }

    public function test_admin_can_promote_user_to_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('toggleAdmin', $user->id);

        $user->refresh();
        $this->assertTrue($user->isAdmin());
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('toggleAdmin', $admin->id);

        $admin->refresh();
        $this->assertTrue($admin->isAdmin());
    }

    public function test_admin_can_delete_user_but_not_themselves(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('delete', $user->id);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);

        // Now try to delete self
        Livewire::actingAs($admin)
            ->test(UsersIndex::class)
            ->call('delete', $admin->id);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_admin_can_edit_user_profile_and_change_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['name' => 'Old Name']);
        $free = Plan::factory()->free()->create();
        $pro = Plan::factory()->pro()->create();

        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $free->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(UserEdit::class, ['user' => $user])
            ->set('name', 'New Name')
            ->set('planId', $pro->id)
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertEquals('New Name', $user->name);
        $this->assertEquals($pro->id, $user->activeSubscription->plan_id);
        $this->assertNotNull($user->activeSubscription->starts_at);
    }

    public function test_admin_can_disable_2fa(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->withTwoFactor()->create();

        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());

        Livewire::actingAs($admin)
            ->test(UserEdit::class, ['user' => $user])
            ->set('resetTwoFactor', true)
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_admin_can_force_password_reset(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $originalHash = $user->password;

        Livewire::actingAs($admin)
            ->test(UserEdit::class, ['user' => $user])
            ->set('forcePasswordReset', true)
            ->set('password', 'newpassword123')
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertNotEquals($originalHash, $user->password);
        $this->assertTrue(Hash::check('newpassword123', $user->password));
    }

    public function test_non_admin_cannot_access_admin_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }
}
