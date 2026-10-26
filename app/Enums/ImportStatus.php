<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Pending = 'pending';
    case Validating = 'validating';
    case Ready = 'ready';
    case Importing = 'importing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Validating => 'Validating',
            self::Ready => 'Ready to import',
            self::Importing => 'Importing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending, self::Validating, self::Importing => 'bg-blue-50 text-blue-700 ring-blue-200',
            self::Ready => 'bg-amber-50 text-amber-700 ring-amber-200',
            self::Completed => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            self::Failed => 'bg-rose-50 text-rose-700 ring-rose-200',
        };
    }

    public function isBusy(): bool
    {
        return in_array($this, [self::Pending, self::Validating, self::Importing], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }
}
