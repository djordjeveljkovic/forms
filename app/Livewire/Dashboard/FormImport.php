<?php

namespace App\Livewire\Dashboard;

use App\Enums\FormFieldType;
use App\Models\AuditLog;
use App\Models\Form;
use App\Services\FormExporter;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

#[Title('Import form')]
#[Layout('layouts.app')]
class FormImport extends Component
{
    use WithFileUploads;

    public mixed $file = null;

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    public string $rawJson = '';

    public string $importError = '';

    /**
     * Mode of the staged payload: 'full' or 'fields'.
     */
    public string $previewMode = 'full';

    // Form config fields, only shown when previewMode === 'fields'.
    public string $formName = '';

    public string $formDescription = '';

    /** @var array<int, string> */
    public array $formRecipientEmails = [''];

    public string $formFromEmail = '';

    public string $formFromName = '';

    public string $formSubjectTemplate = 'New submission for :form_name';

    public string $formSuccessMessage = 'Thank you for your submission.';

    public string $formAllowedOrigins = '';

    public bool $formStoreSubmissions = true;

    public bool $formSendEmail = true;

    public bool $formAutoDiscoverFields = true;

    /**
     * Build a preview when a file is uploaded.
     */
    public function updatedFile(): void
    {
        $this->validateOnly('file', [
            'file' => ['nullable', 'file', 'mimetypes:application/json,text/plain', 'max:512'],
        ]);
        $this->resetPreview();
        $this->previewFromFile();
    }

    /**
     * Build a preview from the raw JSON textarea.
     */
    public function updatedRawJson(): void
    {
        $this->resetPreview();
        $this->previewFromString();
    }

    /**
     * Persist the staged payload as a new form.
     */
    public function import(): void
    {
        if (! $this->preview) {
            $this->importError = 'Upload a JSON file or paste a form configuration first.';

            return;
        }

        try {
            $overrides = $this->previewMode === 'fields' ? $this->buildFormOverrides() : null;
            $form = DB::transaction(fn (): Form => app(FormExporter::class)
                ->import($this->preview, $this->resolveImportName(), $overrides));
        } catch (Throwable $exception) {
            $this->importError = $exception->getMessage();

            return;
        }

        AuditLog::query()->create([
            'user_id' => Auth::id(),
            'action' => 'form.imported',
            'auditable_type' => $form->getMorphClass(),
            'auditable_id' => $form->id,
            'ip_address' => request()->ip(),
        ]);

        Flux::toast(variant: 'success', text: __('Form imported.'));

        $this->reset(['file', 'rawJson', 'preview', 'importError']);
        $this->formRecipientEmails = [''];

        $this->redirectRoute('dashboard.forms.edit', $form, navigate: true);
    }

    /**
     * Reset staged data and clear the preview.
     */
    public function resetPreview(): void
    {
        $this->preview = null;
        $this->importError = '';
    }

    /**
     * Add an empty recipient field (used by the fields-only import panel).
     */
    public function addFormRecipient(): void
    {
        $this->formRecipientEmails[] = '';
    }

    /**
     * Remove a recipient field by index.
     */
    public function removeFormRecipient(int $index): void
    {
        unset($this->formRecipientEmails[$index]);
        $this->formRecipientEmails = array_values($this->formRecipientEmails);

        if (empty($this->formRecipientEmails)) {
            $this->formRecipientEmails = [''];
        }
    }

    /**
     * Build the preview from the uploaded file.
     */
    protected function previewFromFile(): void
    {
        if (! $this->file instanceof UploadedFile) {
            return;
        }

        try {
            $contents = file_get_contents($this->file->getRealPath());
        } catch (Throwable) {
            $this->importError = 'Unable to read the uploaded file.';

            return;
        }

        if ($contents === false) {
            $this->importError = 'Unable to read the uploaded file.';

            return;
        }

        $this->rawJson = $contents;
        $this->previewFromString();
    }

    /**
     * Build a preview from the raw JSON textarea.
     */
    protected function previewFromString(): void
    {
        if (trim($this->rawJson) === '') {
            return;
        }

        try {
            $decoded = json_decode($this->rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->importError = 'Invalid JSON: '.$exception->getMessage();

            return;
        }

        if (! is_array($decoded) || $decoded === []) {
            $this->importError = 'JSON must be an object or a non-empty list.';

            return;
        }

        $this->previewMode = $this->detectMode($decoded);

        $validator = validator($decoded, $this->previewRules());
        if ($validator->fails()) {
            $this->importError = $validator->errors()->first();

            return;
        }

        $this->preview = $decoded;

        if ($this->previewMode === 'fields') {
            $this->seedFormConfigFromPreview($decoded);
        }
    }

    /**
     * Determine whether the JSON is a full form config or a fields-only payload.
     *
     * @param  array<int|string, mixed>  $decoded
     */
    protected function detectMode(array $decoded): string
    {
        if (isset($decoded['form']) && is_array($decoded['form'])) {
            return 'full';
        }

        return 'fields';
    }

    /**
     * Pre-populate the form config inputs from the payload when possible.
     *
     * @param  array<int|string, mixed>  $decoded
     */
    protected function seedFormConfigFromPreview(array $decoded): void
    {
        if (isset($decoded['name']) && is_string($decoded['name'])) {
            $this->formName = $decoded['name'].' (imported)';
        }

        if (isset($decoded['description']) && is_string($decoded['description'])) {
            $this->formDescription = $decoded['description'];
        }

        if (isset($decoded['recipient_emails']) && is_array($decoded['recipient_emails'])) {
            $emails = collect($decoded['recipient_emails'])
                ->map(fn ($email) => (string) $email)
                ->filter()
                ->all();

            if (! empty($emails)) {
                $this->formRecipientEmails = $emails;
            }
        }
    }

    /**
     * Build the form-config overrides supplied to the service for fields-only imports.
     *
     * @return array<string, mixed>
     */
    protected function buildFormOverrides(): array
    {
        $recipients = collect($this->formRecipientEmails)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter()
            ->values()
            ->all();

        $allowedOrigins = collect(explode("\n", $this->formAllowedOrigins))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all();

        return [
            'name' => $this->formName,
            'description' => $this->formDescription ?: null,
            'recipient_emails' => $recipients,
            'from_email' => $this->formFromEmail ?: null,
            'from_name' => $this->formFromName ?: null,
            'subject_template' => $this->formSubjectTemplate,
            'success_message' => $this->formSuccessMessage,
            'allowed_origins' => $allowedOrigins,
            'store_submissions' => $this->formStoreSubmissions,
            'send_email' => $this->formSendEmail,
            'auto_discover_fields' => $this->formAutoDiscoverFields,
        ];
    }

    /**
     * Determine the import name.
     */
    protected function resolveImportName(): string
    {
        if ($this->previewMode === 'full') {
            $name = (string) ($this->preview['form']['name'] ?? '');

            return $name !== '' ? $name.' (imported)' : 'Imported form';
        }

        return $this->formName !== '' ? $this->formName : 'Imported form';
    }

    /**
     * Validation rules for the JSON preview payload.
     *
     * @return array<string, mixed>
     */
    protected function previewRules(): array
    {
        $typeValues = array_map(fn (FormFieldType $t) => $t->value, FormFieldType::cases());

        return [
            'form' => ['sometimes', 'array'],
            'form.name' => ['required_with:form', 'string', 'max:255'],
            'form.description' => ['nullable', 'string', 'max:2000'],
            'form.recipient_emails' => ['required_with:form', 'array', 'min:1'],
            'form.recipient_emails.*' => ['required_with:form', 'email'],
            'form.from_email' => ['nullable', 'email', 'max:255'],
            'form.from_name' => ['nullable', 'string', 'max:255'],
            'form.subject_template' => ['nullable', 'string', 'max:255'],
            'form.allowed_origins' => ['nullable', 'array'],
            'form.allowed_origins.*' => ['string'],
            'form.store_submissions' => ['nullable', 'boolean'],
            'form.send_email' => ['nullable', 'boolean'],
            'form.success_message' => ['nullable', 'string', 'max:2000'],
            'form.submitter_reply_to_field' => ['nullable', 'string', 'max:64'],
            'form.auto_discover_fields' => ['nullable', 'boolean'],

            'fields' => ['sometimes', 'array', 'min:1'],
            'fields.*' => ['array'],
            'fields.*.name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.type' => ['required', 'string', Rule::in($typeValues)],
            'fields.*.required' => ['nullable', 'boolean'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.help_text' => ['nullable', 'string', 'max:1000'],
            'fields.*.default_value' => ['nullable'],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.options.*' => ['string'],
            'fields.*.validation_rules' => ['nullable', 'array'],
            'fields.*.validation_rules.*' => ['string'],
        ];
    }

    public function render(): View
    {
        return view('livewire.dashboard.form-import');
    }
}
