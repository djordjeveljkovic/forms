<?php

namespace App\Services;

use App\Enums\FormFieldType;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Support\Str;

/**
 * Infers a set of field definitions from a submission payload, then persists
 * them against the form. Used by the auto-discover-fields behaviour when
 * a form has no configured fields yet.
 */
class FormFieldDiscoverer
{
    /**
     * Create a FormField for every key in the supplied data and persist them.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, FormField>
     */
    public function discover(Form $form, array $data): array
    {
        $created = [];
        $position = 0;

        foreach (array_keys($data) as $rawKey) {
            $key = (string) $rawKey;
            $sanitised = $this->sanitiseKey($key);
            if ($sanitised === null) {
                continue;
            }

            $value = $data[$key] ?? null;
            $type = $this->inferType($sanitised, $value);

            $created[] = $form->fields()->create([
                'name' => $sanitised,
                'label' => $this->humanise($key),
                'type' => $type->value,
                'required' => false,
                'placeholder' => null,
                'help_text' => null,
                'default_value' => null,
                'options' => $type->hasOptions() && is_array($value) ? array_values($value) : null,
                'validation_rules' => null,
                'position' => $position++,
                'is_active' => true,
            ]);
        }

        return $created;
    }

    /**
     * Convert an arbitrary key (e.g. "Full Name", "e-mail") into a valid
     * field name. Returns null if the key cannot be salvaged.
     */
    public function sanitiseKey(string $key): ?string
    {
        $cleaned = preg_replace('/[^A-Za-z0-9_]+/', '_', $key) ?? '';
        $cleaned = trim($cleaned, '_');

        if ($cleaned === '') {
            return null;
        }

        if (! preg_match('/^[A-Za-z]/', $cleaned)) {
            $cleaned = 'f_'.$cleaned;
        }

        return Str::limit($cleaned, 64, '');
    }

    /**
     * Humanise a raw key for use as a label.
     */
    public function humanise(string $key): string
    {
        $label = preg_replace('/[_\-]+/', ' ', $key) ?? $key;
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;

        return Str::headline($label);
    }

    /**
     * Infer a field type from the key and a sample value.
     */
    public function inferType(string $key, mixed $value): FormFieldType
    {
        $keyLower = strtolower($key);

        // Key-based heuristics take priority.
        foreach ($this->keyHeuristics() as $needle => $type) {
            if (str_contains($keyLower, $needle)) {
                return $type;
            }
        }

        // Value-based heuristics.
        if (is_bool($value)) {
            return FormFieldType::Checkbox;
        }

        if (is_array($value)) {
            return FormFieldType::Checkbox;
        }

        if (is_int($value) || is_float($value)) {
            return FormFieldType::Number;
        }

        if (is_string($value)) {
            if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                return FormFieldType::Email;
            }

            if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
                return FormFieldType::Url;
            }

            if (is_numeric($value)) {
                return FormFieldType::Number;
            }

            if (mb_strlen($value) > 200) {
                return FormFieldType::Textarea;
            }
        }

        return FormFieldType::Text;
    }

    /**
     * Map of key substrings to preferred field types.
     *
     * @return array<string, FormFieldType>
     */
    protected function keyHeuristics(): array
    {
        return [
            'email' => FormFieldType::Email,
            'e-mail' => FormFieldType::Email,
            'phone' => FormFieldType::Tel,
            'tel' => FormFieldType::Tel,
            'mobile' => FormFieldType::Tel,
            'url' => FormFieldType::Url,
            'website' => FormFieldType::Url,
            'date' => FormFieldType::Date,
            'birthday' => FormFieldType::Date,
            'time' => FormFieldType::Time,
            'message' => FormFieldType::Textarea,
            'description' => FormFieldType::Textarea,
            'body' => FormFieldType::Textarea,
            'comment' => FormFieldType::Textarea,
            'notes' => FormFieldType::Textarea,
            'feedback' => FormFieldType::Textarea,
        ];
    }
}
