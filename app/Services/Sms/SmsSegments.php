<?php

namespace App\Services\Sms;

use App\Enums\SmsEncoding;

/**
 * Result of an SMS segment estimate. "Segments" is the estimated number of
 * billable SMS units - clearly an estimate, since the provider may split or
 * bill differently.
 */
final readonly class SmsSegments
{
    public function __construct(
        public SmsEncoding $encoding,
        public int $length,
        public int $segments,
        public int $units,
        public bool $isMultipart,
    ) {}

    public function toArray(): array
    {
        return [
            'encoding' => $this->encoding->value,
            'encoding_label' => $this->encoding->label(),
            'length' => $this->length,
            'segments' => $this->segments,
            'units' => $this->units,
            'is_multipart' => $this->isMultipart,
        ];
    }
}
