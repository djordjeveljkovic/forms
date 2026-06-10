<?php

namespace App\Livewire\Dashboard;

use App\Enums\FormFieldType;
use App\Models\AuditLog;
use App\Models\Form;
use App\Models\FormField;
use App\Services\FormExporter;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Title('Edit form')]
#[Layout('layouts.app')]
class FormEdit extends Component
{
    public Form $form;

    public string $name = '';

    public string $description = '';

    /** @var array<int, string> */
    public array $recipientEmails = [];

    public string $fromEmail = '';

    public string $fromName = '';

    public string $subjectTemplate = '';

    public string $allowedOrigins = '';

    public bool $storeSubmissions = true;

    public bool $sendEmail = true;

    public string $successMessage = '';

    public string $submitterReplyToField = '';

    public bool $autoDiscoverFields = true;

    /** @var array<int, array<string, mixed>> */
    public array $fields = [];

    #[Locked]
    public string $apiKey = '';

    /**
     * Mount the component.
     */
    public function mount(Form $form): void
    {
        $this->form = $form;
        $this->name = $form->name;
        $this->description = (string) $form->description;
        $this->recipientEmails = $form->recipient_emails ?: [''];
        $this->fromEmail = (string) $form->from_email;
        $this->fromName = (string) $form->from_name;
        $this->subjectTemplate = $form->subject_template;
        $this->allowedOrigins = $form->allowed_origins ? implode("\n", $form->allowed_origins) : '';
        $this->storeSubmissions = (bool) $form->store_submissions;
        $this->sendEmail = (bool) $form->send_email;
        $this->successMessage = $form->success_message;
        $this->submitterReplyToField = (string) $form->submitter_reply_to_field;
        $this->autoDiscoverFields = (bool) $form->auto_discover_fields;
        $this->apiKey = $form->api_key;
        $this->fields = $form->fields()
            ->orderBy('position')
            ->get()
            ->map(fn (FormField $field) => $this->fieldRowFromModel($field))
            ->all();

        if (empty($this->fields)) {
            $this->addField();
        }
    }

    /**
     * Update the form and its configured fields.
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

        DB::transaction(function () use ($data, $recipients, $allowed, $fieldsData): void {
            $this->form->update([
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
                'auto_discover_fields' => $data['autoDiscoverFields'],
            ]);

            // Re-sync fields: delete and recreate is simplest, since position is full re-derived.
            $this->form->fields()->delete();
            foreach ($fieldsData as $index => $field) {
                $this->form->fields()->create([
                    ...$field,
                    'position' => $index,
                ]);
            }
        });

        $this->audit('form.updated');

        Flux::toast(variant: 'success', text: __('Form updated.'));

        // Refresh in-memory fields.
        $this->fields = $this->form->fields()
            ->orderBy('position')
            ->get()
            ->map(fn (FormField $field) => $this->fieldRowFromModel($field))
            ->all();
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
        $this->fields[] = $this->emptyFieldRow();
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
     * Regenerate the API key.
     */
    public function regenerateApiKey(): void
    {
        $this->form->regenerateApiKey();
        $this->apiKey = $this->form->api_key;
        $this->audit('form.api_key.regenerated');

        Flux::toast(variant: 'success', text: __('API key regenerated.'));
    }

    /**
     * Build a JSON document with the full form configuration and stream it
     * back to the browser as a downloadable file.
     */
    public function exportJson(): StreamedResponse
    {
        $payload = app(FormExporter::class)->export($this->form);
        $filename = 'form-'.$this->form->slug.'-'.now()->format('Y-m-d-His').'.json';

        return response()->streamDownload(function () use ($payload): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                fwrite($handle, $json);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Delete the form permanently.
     */
    public function delete(): void
    {
        $form = $this->form;
        $this->audit('form.deleted');
        $form->delete();

        Flux::toast(variant: 'success', text: __('Form deleted.'));

        $this->redirectRoute('dashboard.forms.index', navigate: true);
    }

    /**
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
     * @return array<string, mixed>
     */
    protected function fieldRowFromModel(FormField $field): array
    {
        return [
            'name' => $field->name,
            'label' => $field->label,
            'type' => $field->type,
            'required' => (bool) $field->required,
            'placeholder' => (string) $field->placeholder,
            'help_text' => (string) $field->help_text,
            'options' => is_array($field->options) ? implode("\n", $field->options) : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyFieldRow(): array
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

    protected function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
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
            'submitterReplyToField' => ['nullable', 'string', 'max:64', Rule::notIn(['__data', '_token'])],
            'autoDiscoverFields' => ['boolean'],
        ];
    }

    /**
     * Log an audit event.
     */
    protected function audit(string $action): void
    {
        try {
            AuditLog::query()->create([
                'user_id' => Auth::id(),
                'action' => $action,
                'auditable_type' => $this->form->getMorphClass(),
                'auditable_id' => $this->form->id,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Throwable) {
            // ignore
        }
    }

    public function render(): View
    {
        return view('livewire.dashboard.form-edit');
    }
}
