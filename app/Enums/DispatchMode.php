<?php

namespace App\Enums;

enum DispatchMode: string
{
    /** One provider request per batch of up to 100 identical messages. */
    case Bulk = 'bulk';

    /** One provider request per recipient. Required for personalisation. */
    case Individual = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::Bulk => 'Bulk (batched)',
            self::Individual => 'Individual (per recipient)',
        };
    }
}
