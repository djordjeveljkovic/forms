<?php

namespace App\Services;

use App\Enums\FormFieldType;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Serialise and rehydrate form configurations to/from a portable JSON document.
 */
class FormExporter
{
    /**
     * Schema version of the export payload.
     */
    public const VERSION = '1.0';

    /**
     * Build the export payload for a form.
     *
     * @return array<string, mixed>
     */
    public function export(Form $form): array
    {
        $form->loadMissing('fields');

        return [
            'version' => self::VERSION,
            'exported_at' => Carbon::now()->toIso8601String(),
            'form' => $this->exportForm($form),
            'fields' => $form->fields
                ->sortBy('position')
                ->map(fn (FormField $field) => $this->exportField($field))
                ->values()
                ->all(),
        ];
    }

    /**
     * Build a brand-new form (with fields) from a previously exported payload.
     *
     * The payload may be one of:
     *  - a full export document with `form` and `fields`
     *  - an object containing only `fields`
     *  - a bare list of field definitions
     *
     * @param  array<int|string, mixed>|array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $formOverrides  used when no `form` section is present
     */
    public function import(array $payload, ?string $nameOverride = null, ?array $formOverrides = null): Form
    {
        $fieldsInput = $this->extractFields($payload);
        $formInput = $this->extractFormInput($payload, $formOverrides);

        if ($formInput === null) {
            throw new InvalidArgumentException(
                'Export payload is missing a "form" section and no form overrides were provided.'
            );
        }

        $name = $nameOverride ?: (string) ($formInput['name'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException('Form name is required.');
        }

        /** @var array<int, string|null> $recipientsInput */
        $recipientsInput = (array) ($formInput['recipient_emails'] ?? []);
        $recipients = collect($recipientsInput)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter()
            ->values()
            ->all();

        if (empty($recipients)) {
            throw new InvalidArgumentException('At least one recipient email is required.');
        }

        /** @var array<int, string|null> $allowedOriginsInput */
        $allowedOriginsInput = (array) ($formInput['allowed_origins'] ?? []);
        $allowedOrigins = collect($allowedOriginsInput)
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->values()
            ->all();

        $form = Form::query()->create([
            'name' => $name,
            'description' => $formInput['description'] ?? null,
            'recipient_emails' => $recipients,
            'from_email' => $formInput['from_email'] ?? null,
            'from_name' => $formInput['from_name'] ?? null,
            'subject_template' => $formInput['subject_template'] ?? 'New submission for :form_name',
            'allowed_origins' => $allowedOrigins ?: null,
            'store_submissions' => (bool) ($formInput['store_submissions'] ?? true),
            'send_email' => (bool) ($formInput['send_email'] ?? true),
            'submitter_reply_to_field' => $formInput['submitter_reply_to_field'] ?? null,
            'success_message' => $formInput['success_message'] ?? 'Thank you for your submission.',
            'success_redirect_url' => $this->nullable($formInput['success_redirect_url'] ?? null),
            'min_submission_seconds' => (int) ($formInput['min_submission_seconds'] ?? 3),
            'honeypot_field' => (string) ($formInput['honeypot_field'] ?? 'website'),
            'captcha_provider' => (string) ($formInput['captcha_provider'] ?? 'none'),
            // Captcha keys are intentionally NOT exported. They are
            // per-account secrets that should be re-entered on the
            // destination site.
            'auto_discover_fields' => (bool) ($formInput['auto_discover_fields'] ?? true),
        ]);

        foreach ($fieldsInput as $index => $fieldInput) {
            /** @var array<string, mixed> $fieldInput */
            $type = (string) ($fieldInput['type'] ?? '');
            $typeEnum = FormFieldType::tryFrom($type);

            if ($typeEnum === null) {
                throw new RuntimeException("Invalid field type '{$type}' on field #{$index}.");
            }

            /** @var array<int, string|null> $optionsInput */
            $optionsInput = (array) ($fieldInput['options'] ?? []);
            $options = $typeEnum->hasOptions()
                ? collect($optionsInput)
                    ->map(fn ($opt) => trim((string) $opt))
                    ->filter()
                    ->values()
                    ->all()
                : null;

            /** @var array<int, string|null> $rulesInput */
            $rulesInput = (array) ($fieldInput['validation_rules'] ?? []);
            $validationRules = collect($rulesInput)
                ->map(fn ($rule) => trim((string) $rule))
                ->filter()
                ->values()
                ->all();

            $form->fields()->create([
                'name' => (string) $fieldInput['name'],
                'label' => (string) $fieldInput['label'],
                'type' => $type,
                'required' => (bool) ($fieldInput['required'] ?? false),
                'placeholder' => $this->nullable($fieldInput['placeholder'] ?? null),
                'help_text' => $this->nullable($fieldInput['help_text'] ?? null),
                'default_value' => $this->nullable($fieldInput['default_value'] ?? null),
                'options' => $options,
                'validation_rules' => $validationRules ?: null,
                'position' => (int) ($fieldInput['position'] ?? $index),
                'is_active' => (bool) ($fieldInput['is_active'] ?? true),
            ]);
        }

        return $form->fresh('fields');
    }

    /**
     * Serialise the form portion of the export.
     *
     * @return array<string, mixed>
     */
    protected function exportForm(Form $form): array
    {
        return [
            'name' => $form->name,
            'description' => $form->description,
            'recipient_emails' => $form->recipient_emails ?: [],
            'from_email' => $form->from_email,
            'from_name' => $form->from_name,
            'subject_template' => $form->subject_template,
            'allowed_origins' => $form->allowed_origins ?: [],
            'store_submissions' => (bool) $form->store_submissions,
            'send_email' => (bool) $form->send_email,
            'submitter_reply_to_field' => $form->submitter_reply_to_field,
            'success_message' => $form->success_message,
            'success_redirect_url' => $form->success_redirect_url,
            'min_submission_seconds' => (int) $form->min_submission_seconds,
            'honeypot_field' => $form->honeypot_field,
            // Captcha keys are intentionally NOT exported.
            'captcha_provider' => $form->captcha_provider,
            'auto_discover_fields' => (bool) $form->auto_discover_fields,
        ];
    }

    /**
     * Serialise a single field.
     *
     * @return array<string, mixed>
     */
    protected function exportField(FormField $field): array
    {
        return [
            'name' => $field->name,
            'label' => $field->label,
            'type' => $field->type,
            'required' => (bool) $field->required,
            'placeholder' => $field->placeholder,
            'help_text' => $field->help_text,
            'default_value' => $field->default_value,
            'options' => $field->options ?: [],
            'validation_rules' => $field->validation_rules ?: [],
            'position' => (int) $field->position,
            'is_active' => (bool) $field->is_active,
        ];
    }

    /**
     * Convert an empty/blank value to null.
     */
    protected function nullable(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Extract a list of field definitions from a (possibly irregular) payload.
     *
     * @param  array<int|string, mixed>|array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    protected function extractFields(array $payload): array
    {
        if (isset($payload['fields']) && is_array($payload['fields'])) {
            /** @var array<int, array<string, mixed>> $fields */
            $fields = $payload['fields'];

            return $fields;
        }

        // Bare list of field definitions.
        if (array_is_list($payload)) {
            /** @var array<int, array<string, mixed>> $fields */
            $fields = $payload;

            return $fields;
        }

        throw new InvalidArgumentException(
            'Export payload does not contain any field definitions. Expected a "fields" array or a list of field objects.'
        );
    }

    /**
     * Extract form configuration from a payload, merging user overrides when
     * the payload does not include a "form" section.
     *
     * @param  array<int|string, mixed>|array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $overrides
     * @return array<string, mixed>|null
     */
    protected function extractFormInput(array $payload, ?array $overrides): ?array
    {
        if (isset($payload['form']) && is_array($payload['form'])) {
            /** @var array<string, mixed> $form */
            $form = $payload['form'];

            return $overrides ? array_merge($form, $overrides) : $form;
        }

        return $overrides;
    }
}
