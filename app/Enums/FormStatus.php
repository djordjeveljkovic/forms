<?php

namespace App\Enums;

enum FormStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Disabled => 'Disabled',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Disabled => 'zinc',
            self::Archived => 'amber',
        };
    }
}
