<?php

namespace App\Models;

use App\Enums\FormFieldType;
use Database\Factories\FormFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $form_id
 * @property string $name
 * @property string $label
 * @property string $type
 * @property bool $required
 * @property string|null $placeholder
 * @property string|null $help_text
 * @property string|null $default_value
 * @property array<int, string>|null $options
 * @property array<int, string>|null $validation_rules
 * @property int $position
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'form_id',
    'name',
    'label',
    'type',
    'required',
    'placeholder',
    'help_text',
    'default_value',
    'options',
    'validation_rules',
    'position',
    'is_active',
])]
class FormField extends Model
{
    /** @use HasFactory<FormFieldFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'is_active' => 'boolean',
            'options' => 'array',
            'validation_rules' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * Get the form that owns this field.
     *
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * Get the field type enum.
     */
    public function typeEnum(): FormFieldType
    {
        return FormFieldType::from($this->type);
    }

    /**
     * Get the validation rules for this field, including type-based defaults.
     *
     * @return array<int, string>
     */
    public function validationRules(): array
    {
        $rules = $this->typeEnum()->defaultValidationRules();

        $extra = (array) ($this->validation_rules ?? []);
        if ($this->required) {
            $extra[] = 'required';
        } else {
            $extra[] = 'nullable';
        }

        return array_values(array_unique(array_merge($rules, $extra)));
    }

    /**
     * Get a public representation of this field (for the public schema endpoint).
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'required' => (bool) $this->required,
            'placeholder' => $this->placeholder,
            'help_text' => $this->help_text,
            'default_value' => $this->default_value,
            'options' => $this->options ?: [],
            'position' => (int) $this->position,
        ];
    }
}
