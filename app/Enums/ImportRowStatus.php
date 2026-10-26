<?php

namespace App\Enums;

enum ImportRowStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Duplicate = 'duplicate';

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Valid => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            self::Invalid => 'bg-rose-50 text-rose-700 ring-rose-200',
            self::Duplicate => 'bg-amber-50 text-amber-700 ring-amber-200',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid',
            self::Invalid => 'Invalid',
            self::Duplicate => 'Duplicate',
        };
    }
}
