<?php

namespace App\Services\Agent;

use App\Enums\FormFieldType;
use App\Models\Form;
use App\Models\FormField;

/**
 * Build a copy-pasteable HTML snippet that posts visitor submissions
 * to the existing `/api/forms/{slug}` endpoint using the form's
 * per-form `api_key`.
 *
 * Security model:
 * - The high-privilege forms-agent user-key (used to create the form
 *   in the first place) is NEVER embedded in the snippet. Only the
 *   per-form key — which is scoped to a single form — leaves the
 *   agent's hands.
 * - The per-form key is carried as a hidden POST body field, not as
 *   a query-string parameter. Putting it in the URL would leak it
 *   into browser history, server access logs, and the `Referer`
 *   header when the user clicks any external link on the success
 *   page.
 */
class EmbedSnippetGenerator
{
    /**
     * Build a snippet for the supplied form.
     *
     * @param  string  $formApiKey  the per-form api_key returned by
     *                              `POST /api/agent/forms`. If empty
     *                              (e.g. when rendering the success
     *                              page for a browser that never
     *                              typed a key in), the snippet uses
     *                              a `__YOUR_FORM_KEY__` placeholder
     *                              so the response HTML does not
     *                              leak a working key.
     */
    public function build(Form $form, string $formApiKey): string
    {
        $form->loadMissing('fields');

        // The endpoint is the legacy per-form route, which already
        // authenticates via api_key (header, query, or body) via the
        // VerifyFormApiKey middleware. No query string here — the
        // key rides in the POST body so it does not appear in
        // browser history, server logs, or Referer headers.
        $endpoint = url('/api/forms/'.$form->slug);

        $rows = [];

        $rows[] = '<form action="'.$this->e($endpoint).'" method="POST" class="space-y-4">';

        // Per-form api_key as a hidden body field. VerifyFormApiKey
        // reads this from `$request->input('api_key')`.
        $displayKey = $formApiKey !== '' ? $formApiKey : '__YOUR_FORM_KEY__';
        $rows[] = '  <input type="hidden" name="api_key" value="'.$this->e($displayKey).'">';

        if ((int) $form->min_submission_seconds > 0) {
            $rows[] = '  <input type="hidden" name="_timestamp" value="'.time().'">';
        }
        if ($redirect = (string) ($form->success_redirect_url ?? '')) {
            $rows[] = '  <input type="hidden" name="_redirect" value="'.$this->e($redirect).'">';
        }

        $honeypot = (string) ($form->honeypot_field ?: 'website');
        $rows[] = '  <!-- honeypot: hidden visually with CSS below -->';
        $rows[] = '  <div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">';
        $rows[] = '    <label>Website <input type="text" name="'.$this->e($honeypot).'" value="" tabindex="-1" autocomplete="off"></label>';
        $rows[] = '  </div>';

        foreach ($form->fields()->orderBy('position')->get() as $field) {
            $rows = array_merge($rows, $this->renderField($field));
        }

        $rows[] = '  <button type="submit">Submit</button>';
        $rows[] = '</form>';

        return implode("\n", $rows);
    }

    /**
     * Render one field as one or more lines of HTML.
     *
     * @return array<int, string>
     */
    protected function renderField(FormField $field): array
    {
        $name = $this->e($field->name);
        $label = $this->e($field->label ?? $field->name);
        $required = $field->required ? ' *' : '';
        $placeholder = $field->placeholder !== null && $field->placeholder !== ''
            ? ' placeholder="'.$this->e($field->placeholder).'"'
            : '';
        $helpText = $field->help_text !== null && $field->help_text !== ''
            ? '  <small>'.$this->e($field->help_text).'</small>'
            : '';
        $requiredAttr = $field->required ? ' required' : '';

        return match ($field->typeEnum()) {
            FormFieldType::Textarea => [
                '  <label>',
                '    '.$label.$required,
                '    <textarea name="'.$name.'"'.$placeholder.$requiredAttr.'></textarea>',
                $helpText ? '    '.$helpText : '    ',
                '  </label>',
            ],
            FormFieldType::Select => $this->renderSelect($field, $label, $required, $requiredAttr, $helpText),
            FormFieldType::Radio => $this->renderRadioGroup($field, $label, $required, $helpText),
            FormFieldType::Checkbox => $this->renderCheckboxGroup($field, $label, $required, $helpText),
            FormFieldType::Hidden => [
                '  <input type="hidden" name="'.$name.'" value="">',
            ],
            default => [
                '  <label>',
                '    '.$label.$required,
                '    <input type="'.$this->e($field->type).'" name="'.$name.'"'.$placeholder.$requiredAttr.'>',
                $helpText ? '    '.$helpText : '    ',
                '  </label>',
            ],
        };
    }

    /**
     * Render a <select> field.
     *
     * @return array<int, string>
     */
    protected function renderSelect(FormField $field, string $label, string $required, string $requiredAttr, string $helpText): array
    {
        $rows = [
            '  <label>',
            '    '.$label.$required,
            '    <select name="'.$this->e($field->name).'"'.$requiredAttr.'>',
            '      <option value="">Select an option</option>',
        ];

        foreach ((array) ($field->options ?? []) as $option) {
            $rows[] = '      <option value="'.$this->e($option).'">'.$this->e($option).'</option>';
        }

        $rows[] = '    </select>';
        $rows[] = $helpText !== '' ? '    '.$helpText : '    ';
        $rows[] = '  </label>';

        return $rows;
    }

    /**
     * Render a radio button group.
     *
     * @return array<int, string>
     */
    protected function renderRadioGroup(FormField $field, string $label, string $required, string $helpText): array
    {
        $rows = [
            '  <fieldset>',
            '    <legend>'.$label.$required.'</legend>',
        ];

        foreach ((array) ($field->options ?? []) as $option) {
            $rows[] = '    <label><input type="radio" name="'.$this->e($field->name).'" value="'.$this->e($option).'"'.$required.'> '.$this->e($option).'</label>';
        }

        $rows[] = $helpText !== '' ? '    '.$helpText : '    ';
        $rows[] = '  </fieldset>';

        return $rows;
    }

    /**
     * Render a checkbox group.
     *
     * @return array<int, string>
     */
    protected function renderCheckboxGroup(FormField $field, string $label, string $required, string $helpText): array
    {
        $rows = [
            '  <fieldset>',
            '    <legend>'.$label.$required.'</legend>',
        ];

        foreach ((array) ($field->options ?? []) as $option) {
            $rows[] = '    <label><input type="checkbox" name="'.$this->e($field->name).'[]" value="'.$this->e($option).'"> '.$this->e($option).'</label>';
        }

        $rows[] = $helpText !== '' ? '    '.$helpText : '    ';
        $rows[] = '  </fieldset>';

        return $rows;
    }

    /**
     * Convenience HTML-escape.
     */
    protected function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
