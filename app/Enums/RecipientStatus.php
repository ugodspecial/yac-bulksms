<?php

namespace App\Enums;

enum RecipientStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Accepted = 'accepted';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Accepted => 'Accepted by provider',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
            self::Unknown => 'Unknown',
            self::Skipped => 'Skipped',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-slate-100 text-slate-600 ring-slate-200',
            self::Processing => 'bg-blue-50 text-blue-700 ring-blue-200',
            self::Accepted => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
            self::Delivered => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            self::Failed => 'bg-rose-50 text-rose-700 ring-rose-200',
            self::Unknown => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::Skipped => 'bg-slate-100 text-slate-500 ring-slate-200',
        };
    }

    /** A message that will not change state again. */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Accepted,
            self::Delivered,
            self::Failed,
            self::Unknown,
            self::Skipped,
        ], true);
    }

    /** Delivered means the provider confirmed handset delivery. */
    public function isDelivered(): bool
    {
        return $this === self::Delivered;
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status) => [$status->value => $status->label()])
            ->all();
    }
}
