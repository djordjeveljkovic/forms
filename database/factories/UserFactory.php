<?php

namespace Database\Factories;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Indicate the user has the admin role.
     */
    public function admin(): static
    {
        return $this->afterCreating(function (User $user): void {
            // Make sure roles and permissions are seeded so the admin
            // actually has the `view-admin-panel` permission that the
            // route middleware checks.
            app(RolesAndPermissionsSeeder::class)->run();

            $adminRole = Role::query()->firstOrCreate(['name' => User::ROLE_ADMIN, 'guard_name' => 'web']);
            $user->assignRole($adminRole);
        });
    }

    /**
     * Configure the model factory to assign the given role after creation.
     */
    public function withRole(string $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            app(RolesAndPermissionsSeeder::class)->run();

            $roleModel = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $user->assignRole($roleModel);
        });
    }
}
