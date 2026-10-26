<?php

namespace App\Enums;

enum SmsEncoding: string
{
    case Gsm7 = 'gsm-7';
    case Ucs2 = 'ucs-2';

    public function label(): string
    {
        return match ($this) {
            self::Gsm7 => 'GSM-7',
            self::Ucs2 => 'UCS-2 (Unicode)',
        };
    }

    /** Termii expects "plain" or "unicode" for the message type field. */
    public function termiiMessageType(): string
    {
        return $this === self::Gsm7 ? 'plain' : 'unicode';
    }
}
