<?php

namespace App\Enums;

enum FormFieldType: string
{
    case Text = 'text';
    case Email = 'email';
    case Tel = 'tel';
    case Url = 'url';
    case Number = 'number';
    case Textarea = 'textarea';
    case Date = 'date';
    case Time = 'time';
    case Select = 'select';
    case Radio = 'radio';
    case Checkbox = 'checkbox';
    case Hidden = 'hidden';
    case File = 'file';

    /**
     * Get the human-readable label for this field type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Text => 'Single line text',
            self::Email => 'Email address',
            self::Tel => 'Phone number',
            self::Url => 'URL',
            self::Number => 'Number',
            self::Textarea => 'Multi-line text',
            self::Date => 'Date',
            self::Time => 'Time',
            self::Select => 'Dropdown (select)',
            self::Radio => 'Radio buttons',
            self::Checkbox => 'Checkboxes',
            self::Hidden => 'Hidden field',
            self::File => 'File upload',
        };
    }

    /**
     * Determine whether the field provides a list of options.
     */
    public function hasOptions(): bool
    {
        return match ($this) {
            self::Select, self::Radio, self::Checkbox => true,
            default => false,
        };
    }

    /**
     * Default Laravel validation rules applied to a value of this type.
     *
     * @return array<int, string>
     */
    public function defaultValidationRules(): array
    {
        return match ($this) {
            self::Email => ['string', 'email:rfc'],
            self::Url => ['string', 'url'],
            self::Number => ['numeric'],
            self::Date => ['date'],
            self::Time => ['date_format:H:i'],
            self::Tel => ['string', 'max:32'],
            self::File => ['file'],
            self::Hidden => ['string'],
            self::Checkbox => ['array'],
            self::Select, self::Radio => ['string'],
            default => ['string'],
        };
    }
}
