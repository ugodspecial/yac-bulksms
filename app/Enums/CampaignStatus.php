<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case NeedsReview = 'needs_review';
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::NeedsReview => 'Needs review',
            self::Scheduled => 'Scheduled',
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::CompletedWithErrors => 'Completed with errors',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::NeedsReview => 'bg-amber-50 text-amber-700 ring-amber-200',
            self::Scheduled => 'bg-sky-50 text-sky-700 ring-sky-200',
            self::Queued => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
            self::Processing => 'bg-blue-50 text-blue-700 ring-blue-200',
            self::Completed => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            self::CompletedWithErrors => 'bg-orange-50 text-orange-700 ring-orange-200',
            self::Failed => 'bg-rose-50 text-rose-700 ring-rose-200',
            self::Cancelled => 'bg-slate-100 text-slate-500 ring-slate-200',
        };
    }

    /** Drafts may be edited in the campaign builder. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::NeedsReview], true);
    }

    /** Statuses for which a campaign can no longer change outcome. */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::CompletedWithErrors,
            self::Failed,
            self::Cancelled,
        ], true);
    }

    /** Statuses from which a campaign may be confirmed and dispatched. */
    public function canBeConfirmed(): bool
    {
        return in_array($this, [self::Draft, self::NeedsReview], true);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Draft, self::NeedsReview, self::Scheduled, self::Queued, self::Processing], true);
    }

    /** Statuses a user may filter on in the campaign index. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status) => [$status->value => $status->label()])
            ->all();
    }
}
