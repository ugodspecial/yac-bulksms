<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Subscribed = 'subscribed';
    case Unsubscribed = 'unsubscribed';

    public function label(): string
    {
        return match ($this) {
            self::Subscribed => 'Subscribed',
            self::Unsubscribed => 'Unsubscribed',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Subscribed => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            self::Unsubscribed => 'bg-slate-100 text-slate-600 ring-slate-200',
        };
    }
}
