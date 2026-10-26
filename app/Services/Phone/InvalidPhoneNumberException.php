<?php

namespace App\Services\Phone;

use InvalidArgumentException;

class InvalidPhoneNumberException extends InvalidArgumentException
{
    public static function forNumber(string $number, ?string $reason = null): self
    {
        $message = sprintf('"%s" is not a valid phone number.', $number);

        if ($reason !== null) {
            $message .= ' '.$reason;
        }

        return new self($message);
    }
}
