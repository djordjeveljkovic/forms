<?php

namespace Database\Factories;

use App\Enums\FormFieldType;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FormField>
 */
class FormFieldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement([
            FormFieldType::Text,
            FormFieldType::Email,
            FormFieldType::Tel,
            FormFieldType::Number,
            FormFieldType::Textarea,
        ]);

        $name = (string) Str::of(fake()->unique()->word())->snake();

        return [
            'form_id' => Form::factory(),
            'name' => $name,
            'label' => ucwords(str_replace('_', ' ', $name)),
            'type' => $type->value,
            'required' => fake()->boolean(40),
            'placeholder' => fake()->sentence(3),
            'help_text' => null,
            'default_value' => null,
            'options' => null,
            'validation_rules' => null,
            'position' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Indicate the field is required.
     */
    public function required(): static
    {
        return $this->state(fn (): array => [
            'required' => true,
        ]);
    }

    /**
     * Indicate the field is an email field.
     */
    public function email(): static
    {
        return $this->state(fn (): array => [
            'type' => FormFieldType::Email->value,
            'name' => 'email',
            'label' => 'Email address',
        ]);
    }
}
