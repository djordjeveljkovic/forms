<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => 'form.created',
            'auditable_type' => 'App\\Models\\Form',
            'auditable_id' => null,
            'metadata' => null,
            'ip_address' => fake()->ipv4(),
            'created_at' => now(),
        ];
    }
}
