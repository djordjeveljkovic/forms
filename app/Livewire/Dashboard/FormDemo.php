<?php

namespace App\Livewire\Dashboard;

use App\Enums\FormFieldType;
use App\Models\Form;
use App\Models\FormField;
use App\Services\FormSubmissionService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Form demo')]
#[Layout('layouts.app')]
class FormDemo extends Component
{
    public Form $form;

    #[Locked]
    public string $apiKey = '';

    /** @var array<string, mixed> */
    public array $values = [];

    #[Url(as: 'tab', except: 'test')]
    public string $activeTab = 'test';

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    /**
     * Mount the component.
     */
    public function mount(Form $form): void
    {
        $this->form = $form;
        $this->apiKey = $form->api_key;

        foreach ($form->fields()->orderBy('position')->get() as $field) {
            $this->values[$field->name] = $field->default_value ?? '';
        }
    }

    /**
     * Run the submission through the same service the public API uses.
     */
    public function submit(): void
    {
        $data = collect($this->values)
            ->reject(fn ($value) => $value === '' || $value === null)
            ->all();

        $result = app(FormSubmissionService::class)
            ->submit($this->form, $data, request());

        $this->result = [
            'status' => $result['status'],
            'body' => [
                'message' => $result['message'],
                'submission' => $result['submission'],
                'errors' => $result['errors'],
                'fields' => $result['fields'],
            ],
        ];

        if ($result['ok']) {
            $this->reset('values');
            $this->mount($this->form);
            Flux::toast(variant: 'success', text: __('Submission accepted by the API.'));
        }
    }

    /**
     * Reset the form values and clear the previous result.
     */
    public function resetForm(): void
    {
        $this->reset('values', 'result');
        $this->mount($this->form);
    }

    /**
     * Switch the active tab.
     */
    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['test', 'code'], true) ? $tab : 'test';
        $this->resetErrorBag();
    }

    /**
     * Get the form's configured fields.
     *
     * @return Collection<int, FormField>
     */
    #[Computed]
    public function fields(): Collection
    {
        return $this->form->fields()->orderBy('position')->get();
    }

    /**
     * HTML form snippet for embedding on external sites.
     */
    #[Computed]
    public function htmlSnippet(): string
    {
        $endpoint = url('/api/forms/'.$this->form->slug);
        $action = $endpoint.'?api_key='.$this->form->api_key;

        $rows = [];
        $rows[] = '<form action="'.htmlspecialchars($action, ENT_QUOTES).'" method="POST" class="space-y-4">';

        foreach ($this->fields() as $field) {
            $rows[] = $this->renderHtmlField($field);
        }

        $rows[] = '  <button type="submit" class="...">Submit</button>';
        $rows[] = '</form>';

        return implode("\n", $rows);
    }

    /**
     * Vanilla JavaScript fetch snippet.
     */
    #[Computed]
    public function jsSnippet(): string
    {
        $endpoint = url('/api/forms/'.$this->form->slug);
        $fieldNames = $this->fields()->pluck('name')->all();

        $body = "{\n";
        $body .= "  data: {\n";
        foreach ($fieldNames as $name) {
            $body .= '    '.$name.': formData.get(\''.$name.'\'),'."\n";
        }
        $body .= "  }\n";
        $body .= '}';

        $template = <<<JS
const form = document.querySelector('#my-form');
form.addEventListener('submit', async (event) => {
  event.preventDefault();
  const formData = new FormData(form);

  const response = await fetch('{$endpoint}', {
    method: 'POST',
    headers: {
      'X-Form-Key': '{$this->form->api_key}',
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify({$body}),
  });

  const result = await response.json();
  if (response.ok) {
    console.log('Submitted:', result);
  } else {
    console.error('Validation failed:', result.errors);
  }
});
JS;

        return $template;
    }

    /**
     * Render an HTML field row for the demo form.
     */
    protected function renderHtmlField(FormField $field): string
    {
        $name = htmlspecialchars($field->name, ENT_QUOTES);
        $label = htmlspecialchars($field->label, ENT_QUOTES);
        $required = $field->required ? ' *' : '';
        $placeholder = htmlspecialchars((string) $field->placeholder, ENT_QUOTES);
        $help = $field->help_text ? "\n    <small>".htmlspecialchars($field->help_text, ENT_QUOTES).'</small>' : '';

        return match ($field->typeEnum()) {
            FormFieldType::Textarea => <<<HTML
  <label>
    {$label}{$required}
    <textarea name="{$name}" placeholder="{$placeholder}"{$this->requiredAttr($field)}></textarea>{$help}
  </label>
HTML,
            FormFieldType::Select => $this->renderSelectField($field, $label, $required, $name, $placeholder, $help),
            FormFieldType::Radio => $this->renderRadioField($field, $label, $required, $name, $help),
            FormFieldType::Checkbox => $this->renderCheckboxField($field, $label, $required, $name, $help),
            FormFieldType::Hidden => <<<HTML
  <input type="hidden" name="{$name}" value="">
HTML,
            default => $this->renderInputField($field, $label, $required, $name, $placeholder, $help),
        };
    }

    /**
     * Render a basic <input> field.
     */
    protected function renderInputField(FormField $field, string $label, string $required, string $name, string $placeholder, string $help): string
    {
        $type = $field->type;

        return <<<HTML
  <label>
    {$label}{$required}
    <input type="{$type}" name="{$name}" placeholder="{$placeholder}"{$this->requiredAttr($field)}>{$help}
  </label>
HTML;
    }

    /**
     * Render a <select> field.
     */
    protected function renderSelectField(FormField $field, string $label, string $required, string $name, string $placeholder, string $help): string
    {
        $options = '';
        foreach ((array) $field->options as $option) {
            $optionEsc = htmlspecialchars($option, ENT_QUOTES);
            $options .= "\n    <option value=\"{$optionEsc}\">{$optionEsc}</option>";
        }

        $placeholderOption = $placeholder !== '' ? $placeholder : 'Select an option';

        return <<<HTML
  <label>
    {$label}{$required}
    <select name="{$name}"{$this->requiredAttr($field)}>
      <option value="">{$placeholderOption}</option>{$options}
    </select>{$help}
  </label>
HTML;
    }

    /**
     * Render a radio group.
     */
    protected function renderRadioField(FormField $field, string $label, string $required, string $name, string $help): string
    {
        $radios = '';
        foreach ((array) $field->options as $option) {
            $optionEsc = htmlspecialchars($option, ENT_QUOTES);
            $radios .= "\n    <label><input type=\"radio\" name=\"{$name}\" value=\"{$optionEsc}\"> {$optionEsc}</label>";
        }

        return <<<HTML
  <fieldset>
    <legend>{$label}{$required}</legend>{$radios}
{$help}
  </fieldset>
HTML;
    }

    /**
     * Render a checkbox group.
     */
    protected function renderCheckboxField(FormField $field, string $label, string $required, string $name, string $help): string
    {
        $checks = '';
        foreach ((array) $field->options as $option) {
            $optionEsc = htmlspecialchars($option, ENT_QUOTES);
            $checks .= "\n    <label><input type=\"checkbox\" name=\"{$name}[]\" value=\"{$optionEsc}\"> {$optionEsc}</label>";
        }

        return <<<HTML
  <fieldset>
    <legend>{$label}{$required}</legend>{$checks}
{$help}
  </fieldset>
HTML;
    }

    /**
     * Build the `required` HTML attribute.
     */
    protected function requiredAttr(FormField $field): string
    {
        return $field->required ? ' required' : '';
    }

    public function render(): View
    {
        return view('livewire.dashboard.form-demo');
    }
}
