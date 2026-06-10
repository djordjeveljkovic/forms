<?php

namespace App\Enums;

enum SubmissionStatus: string
{
    case Received = 'received';
    case Read = 'read';
    case Archived = 'archived';
    case Spam = 'spam';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Read => 'Read',
            self::Archived => 'Archived',
            self::Spam => 'Spam',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Received => 'blue',
            self::Read => 'green',
            self::Archived => 'zinc',
            self::Spam => 'red',
        };
    }
}
