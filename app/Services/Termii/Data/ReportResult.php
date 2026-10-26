<?php

namespace App\Services\Termii\Data;

use App\Enums\DeliveryState;
use App\Enums\ProviderErrorType;

final readonly class ReportResult
{
    public function __construct(
        public bool $ok,
        public ?DeliveryState $state = null,
        public ?string $providerStatus = null,
        public ?string $message = null,
        public ?ProviderErrorType $errorType = null,
        public array $raw = [],
    ) {}

    public function safeMessage(): string
    {
        if ($this->ok) {
            return $this->message ?: 'Delivery report retrieved.';
        }

        return $this->message ?: ($this->errorType?->label() ?? 'Unable to retrieve the delivery report.');
    }
}
