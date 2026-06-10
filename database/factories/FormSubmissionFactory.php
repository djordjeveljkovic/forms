<?php

namespace Database\Factories;

use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormSubmission>
 */
class FormSubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'submission_data' => [
                'name' => fake()->name(),
                'email' => fake()->safeEmail(),
                'message' => fake()->paragraph(),
            ],
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'referer' => fake()->url(),
            'status' => SubmissionStatus::Received->value,
            'read_at' => null,
        ];
    }

    /**
     * Indicate that the submission has been read.
     */
    public function read(): static
    {
        return $this->state(fn (): array => [
            'status' => SubmissionStatus::Read->value,
            'read_at' => now(),
        ]);
    }
}
