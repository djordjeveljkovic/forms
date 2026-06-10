<?php

namespace Database\Factories;

use App\Models\Form;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $words = fake()->unique()->words(2);
        $name = is_array($words) ? implode(' ', $words) : $words;

        return [
            'name' => ucwords($name),
            'slug' => Str::slug($name).'-'.Str::random(6),
            'description' => fake()->sentence(),
            'endpoint' => '/api/forms/'.Str::slug($name),
            'api_key' => Str::random(48),
            'recipient_emails' => [fake()->safeEmail()],
            'from_email' => null,
            'from_name' => null,
            'subject_template' => 'New submission for :form_name',
            'allowed_origins' => null,
            'store_submissions' => true,
            'send_email' => true,
            'success_notify_submitter' => false,
            'submitter_reply_to_field' => 'email',
            'success_message' => 'Thank you for your submission.',
            'auto_discover_fields' => true,
            'is_archived' => false,
            'archived_at' => null,
        ];
    }

    /**
     * Indicate that the form is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (): array => [
            'is_archived' => true,
            'archived_at' => now(),
        ]);
    }

    /**
     * Disable auto-discover-fields on the form.
     */
    public function noAutoDiscover(): static
    {
        return $this->state(fn (): array => [
            'auto_discover_fields' => false,
        ]);
    }

    /**
     * Indicate that the form does not send email.
     */
    public function noEmail(): static
    {
        return $this->state(fn (): array => [
            'send_email' => false,
        ]);
    }

    /**
     * Indicate that the form does not store submissions.
     */
    public function noStorage(): static
    {
        return $this->state(fn (): array => [
            'store_submissions' => false,
        ]);
    }
}
