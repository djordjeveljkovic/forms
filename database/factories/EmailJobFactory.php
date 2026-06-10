<?php

namespace Database\Factories;

use App\Enums\EmailJobStatus;
use App\Models\EmailJob;
use App\Models\FormSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailJob>
 */
class EmailJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => FormSubmission::factory(),
            'status' => EmailJobStatus::Pending->value,
            'recipient' => fake()->safeEmail(),
            'subject' => 'New submission',
            'body' => 'You have received a new submission.',
            'attempts' => 0,
            'error_message' => null,
            'queued_at' => now(),
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    /**
     * Indicate that the job is sent.
     */
    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => EmailJobStatus::Sent->value,
            'attempts' => 1,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the job failed.
     */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => EmailJobStatus::Failed->value,
            'attempts' => 1,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
            'error_message' => 'SMTP connection refused.',
        ]);
    }
}
