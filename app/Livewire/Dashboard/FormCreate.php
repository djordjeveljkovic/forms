<?php

namespace App\Livewire\Dashboard;

use App\Enums\FormFieldType;
use App\Models\AuditLog;
use App\Models\Form;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('New form')]
#[Layout('layouts.app')]
class FormCreate extends Component
{
    public string $name = '';

    public string $description = '';

    /** @var array<int, string> */
    public array $recipientEmails = [''];

    public string $fromEmail = '';

    public string $fromName = '';

    public string $subjectTemplate = 'New submission for :form_name';

    public string $allowedOrigins = '';

    public bool $storeSubmissions = true;

    public bool $sendEmail = true;

    public string $successMessage = 'Thank you for your submission.';

    public string $successRedirectUrl = '';

    public int $minSubmissionSeconds = 3;

    public string $honeypotField = 'website';

    public string $captchaProvider = 'none';

    public string $captchaSiteKey = '';

    public string $captchaSecretKey = '';

    public string $submitterReplyToField = 'email';

    public bool $autoDiscoverFields = true;

    /** @var array<int, array<string, mixed>> */
    public array $fields = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->addField();
    }

    /**
     * Save the form and its configured fields.
     */
    public function save(): void
    {
        $data = $this->validate($this->rules());

        $recipients = $this->resolveRecipients($data['recipientEmails'] ?? []);

        if (empty($recipients)) {
            $this->addError('recipientEmails', 'At least one recipient email is required.');

            return;
        }

        $allowed = $this->resolveAllowedOrigins();

        [$fieldsData, $fieldsError] = $this->prepareFields($this->fields);
        if ($fieldsError !== null) {
            $this->addError('fields', $fieldsError);

            return;
        }

        try {
            $form = DB::transaction(function () use ($data, $recipients, $allowed, $fieldsData): Form {
                $form = Form::query()->create([
                    'name' => $data['name'],
                    'description' => $data['description'] ?: null,
                    'recipient_emails' => $recipients,
                    'from_email' => $data['fromEmail'] ?: null,
                    'from_name' => $data['fromName'] ?: null,
                    'subject_template' => $data['subjectTemplate'],
                    'allowed_origins' => $allowed ?: null,
                    'store_submissions' => $data['storeSubmissions'],
                    'send_email' => $data['sendEmail'],
                    'submitter_reply_to_field' => $data['submitterReplyToField'] ?: null,
                    'success_message' => $data['successMessage'],
                    'success_redirect_url' => $data['successRedirectUrl'] ?: null,
                    'min_submission_seconds' => $data['minSubmissionSeconds'],
                    'honeypot_field' => $data['honeypotField'] ?: 'website',
                    'captcha_provider' => $data['captchaProvider'],
                    'captcha_site_key' => $data['captchaSiteKey'] ?: null,
                    'captcha_secret_key' => $data['captchaSecretKey'] ?: null,
                    'auto_discover_fields' => $data['autoDiscoverFields'],
                ]);

                foreach ($fieldsData as $index => $field) {
                    $form->fields()->create([
                        ...$field,
                        'position' => $index,
                    ]);
                }

                return $form;
            });
        } catch (QueryException $exception) {
            $this->addError('name', 'Failed to create the form. A unique field may be conflicting.');

            return;
        }

        $this->audit($form, 'form.created');

        Flux::toast(variant: 'success', text: __('Form created.'));

        $this->redirectRoute('dashboard.forms.edit', $form, navigate: true);
    }

    /**
     * Add an empty recipient field.
     */
    public function addRecipient(): void
    {
        $this->recipientEmails[] = '';
    }

    /**
     * Remove a recipient field by index.
     */
    public function removeRecipient(int $index): void
    {
        unset($this->recipientEmails[$index]);
        $this->recipientEmails = array_values($this->recipientEmails);

        if (empty($this->recipientEmails)) {
            $this->recipientEmails = [''];
        }
    }

    /**
     * Add a new empty field row.
     */
    public function addField(): void
    {
        $this->fields[] = $this->emptyFieldRow(count($this->fields));
    }

    /**
     * Remove a field row by index.
     */
    public function removeField(int $index): void
    {
        unset($this->fields[$index]);
        $this->fields = array_values($this->fields);
    }

    /**
     * Move a field up or down in the ordering.
     */
    public function moveField(int $index, string $direction): void
    {
        if ($direction === 'up' && $index > 0) {
            [$this->fields[$index - 1], $this->fields[$index]] = [$this->fields[$index], $this->fields[$index - 1]];
        } elseif ($direction === 'down' && $index < count($this->fields) - 1) {
            [$this->fields[$index + 1], $this->fields[$index]] = [$this->fields[$index], $this->fields[$index + 1]];
        }
    }

    /**
     * Available field types for the select dropdown.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function fieldTypes(): array
    {
        $types = [];

        foreach (FormFieldType::cases() as $type) {
            $types[] = ['value' => $type->value, 'label' => $type->label()];
        }

        return $types;
    }

    /**
     * Resolve recipients from validated form data.
     *
     * @param  array<int, string|null>  $input
     * @return array<int, string>
     */
    protected function resolveRecipients(array $input): array
    {
        return collect($input)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Resolve allowed origins from text area input.
     *
     * @return array<int, string>
     */
    protected function resolveAllowedOrigins(): array
    {
        return collect(explode("\n", $this->allowedOrigins))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Validate, normalise, and prepare fields for persistence.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: array<int, array<string, mixed>>, 1: ?string}
     */
    protected function prepareFields(array $rows): array
    {
        $cleaned = [];
        $seen = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $label = trim((string) ($row['label'] ?? ''));
            $type = (string) ($row['type'] ?? FormFieldType::Text->value);

            if ($name === '' && $label === '') {
                continue;
            }

            if ($name === '' || $label === '') {
                return [[], 'Each field needs both a key (name) and a label.'];
            }

            if (! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)) {
                return [[], "Field '{$name}' must start with a letter and only contain letters, numbers, and underscores."];
            }

            if (in_array($name, $seen, true)) {
                return [[], "Duplicate field key '{$name}'."];
            }
            $seen[] = $name;

            if (! in_array($type, array_map(fn (FormFieldType $t) => $t->value, FormFieldType::cases()), true)) {
                return [[], "Invalid field type '{$type}'."];
            }

            $typeEnum = FormFieldType::from($type);
            $options = $typeEnum->hasOptions()
                ? collect(explode("\n", (string) ($row['options'] ?? '')))
                    ->map(fn ($opt) => trim($opt))
                    ->filter()
                    ->values()
                    ->all()
                : null;

            $cleaned[] = [
                'name' => $name,
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($row['required'] ?? false),
                'placeholder' => $this->nullableString($row['placeholder'] ?? null),
                'help_text' => $this->nullableString($row['help_text'] ?? null),
                'options' => $options,
                'is_active' => true,
            ];
        }

        return [$cleaned, null];
    }

    /**
     * Build an empty field row.
     *
     * @return array<string, mixed>
     */
    protected function emptyFieldRow(int $position): array
    {
        return [
            'name' => '',
            'label' => '',
            'type' => FormFieldType::Text->value,
            'required' => false,
            'placeholder' => '',
            'help_text' => '',
            'options' => '',
        ];
    }

    /**
     * Convert blank strings to null.
     */
    protected function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Validation rules.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'recipientEmails' => ['array'],
            'recipientEmails.*' => ['nullable', 'email'],
            'fromEmail' => ['nullable', 'email', 'max:255'],
            'fromName' => ['nullable', 'string', 'max:255'],
            'subjectTemplate' => ['required', 'string', 'max:255'],
            'storeSubmissions' => ['boolean'],
            'sendEmail' => ['boolean'],
            'successMessage' => ['required', 'string', 'max:2000'],
            'successRedirectUrl' => ['nullable', 'string', 'max:2048', 'url'],
            'minSubmissionSeconds' => ['integer', 'min:0', 'max:600'],
            'honeypotField' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'captchaProvider' => ['required', Rule::in(['none', 'turnstile'])],
            'captchaSiteKey' => ['nullable', 'string', 'max:255'],
            'captchaSecretKey' => ['nullable', 'string', 'max:255'],
            'submitterReplyToField' => ['nullable', 'string', 'max:64', Rule::notIn(['__data', '_token'])],
            'autoDiscoverFields' => ['boolean'],
        ];
    }

    /**
     * Log an audit event.
     */
    protected function audit(Form $form, string $action): void
    {
        try {
            AuditLog::query()->create([
                'user_id' => Auth::id(),
                'action' => $action,
                'auditable_type' => $form->getMorphClass(),
                'auditable_id' => $form->id,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Throwable) {
            // ignore
        }
    }

    public function render(): View
    {
        return view('livewire.dashboard.form-create');
    }
}
