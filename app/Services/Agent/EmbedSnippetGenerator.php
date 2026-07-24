<?php

namespace App\Services\Agent;

use App\Enums\FormFieldType;
use App\Models\Form;
use App\Models\FormField;

/**
 * Build a copy-pasteable HTML snippet that points at a user-key-authenticated
 * submission endpoint.
 *
 * The agent response embeds this snippet so the end-user only needs to
 * paste one block of HTML onto their site. Authentication is done with
 * the user's forms-agent key, carried either as a hidden input or in
 * the action URL's query string.
 */
class EmbedSnippetGenerator
{
    /**
     * Build a snippet for the supplied form.
     *
     * @param  bool  $useQueryString  if true, the user key is appended to the
     *                                form action URL instead of being placed
     *                                in a hidden input. Useful for GET-based
     *                                forms but exposes the key in the HTML.
     */
    public function build(Form $form, string $userApiKey, bool $useQueryString = false): string
    {
        $form->loadMissing('fields');

        $endpoint = url('/api/submit/'.$form->slug);
        if ($useQueryString && $userApiKey !== '') {
            $endpoint .= '?user_api='.rawurlencode($userApiKey);
        }

        $rows = [];

        $rows[] = '<form action="'.$this->e($endpoint).'" method="POST" class="space-y-4">';

        // Internal control fields. The hidden _user_api input is what
        // the new submission endpoint reads (when not in the query
        // string); _timestamp satisfies the min-submission-time check
        // when the form requires it.
        if (! $useQueryString && $userApiKey !== '') {
            $rows[] = '  <input type="hidden" name="_user_api" value="'.$this->e($userApiKey).'">';
        }
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
