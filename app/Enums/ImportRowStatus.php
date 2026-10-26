<?php

namespace App\Enums;

enum ImportRowStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid',
            self::Invalid => 'Invalid',
            self::Duplicate => 'Duplicate',
        };
    }
}
