<?php

namespace App\Services;

use App\Models\Form;
use App\Models\FormField;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Builds and runs dynamic validation rules for form submissions
 * based on the form's configured field definitions.
 */
class FormSubmissionValidator
{
    /**
     * Build a validator for a submission against the form's configured fields.
     *
     * @param  array<string, mixed>  $data
     */
    public static function make(Form $form, array $data): ValidatorContract
    {
        // Reject oversized payloads early to protect against memory pressure.
        $maxKb = (int) config('forms.max_submission_size_kb', 256);
        $approxBytes = strlen(json_encode($data) ?: '');
        if ($maxKb > 0 && $approxBytes > $maxKb * 1024) {
            return Validator::make(['data' => $data], [
                'data' => ['required', 'array', function (string $attribute, mixed $value, \Closure $fail) use ($maxKb): void {
                    $fail("Submission is larger than the {$maxKb} KB limit.");
                }],
            ]);
        }

        $fields = $form->activeFields();

        // Forms without any configured fields fall back to a permissive rule
        // so submissions are accepted and the auto-discover path can take over.
        if ($fields->isEmpty()) {
            return Validator::make(['data' => $data], [
                'data' => ['required', 'array', 'min:1'],
            ]);
        }

        $rules = [];
        $messages = [];
        $attributes = [];

        $allowedKeys = $fields->pluck('name')->all();

        foreach ($fields as $field) {
            /** @var FormField $field */
            $fieldRules = $field->validationRules();

            // Checkbox values must be an array of selected options.
            if ($field->typeEnum()->hasOptions() && $field->type === 'checkbox') {
                $fieldRules = ['array', ...array_filter($fieldRules, fn (string $r) => $r !== 'array')];
            }

            $rules['data.'.$field->name] = $fieldRules;
            $attributes['data.'.$field->name] = $field->label;

            if ($field->required) {
                $messages["data.{$field->name}.required"] = 'The :attribute field is required.';
            }

            // For option-based fields, restrict values to the configured options.
            if ($field->typeEnum()->hasOptions() && is_array($field->options) && count($field->options) > 0) {
                if ($field->type === 'checkbox') {
                    $rules['data.'.$field->name.'.*'] = [
                        'string',
                        Rule::in($field->options),
                    ];
                } else {
                    $rules['data.'.$field->name][] = Rule::in($field->options);
                }
            }
        }

        // Reject any keys the form doesn't know about to keep payloads tight.
        $rules['data'] = [
            'required',
            'array',
            function (string $attribute, mixed $value, \Closure $fail) use ($allowedKeys): void {
                if (! is_array($value)) {
                    return;
                }

                $unknown = array_diff(array_keys($value), $allowedKeys);
                if (count($unknown) > 0) {
                    $fail('Unknown field(s): '.implode(', ', $unknown));
                }
            },
        ];

        return Validator::make(['data' => $data], $rules, $messages, $attributes);
    }
}
