<?php

namespace App\Services\Termii\Data;

use App\Enums\ProviderErrorType;

final readonly class BalanceResult
{
    public function __construct(
        public bool $ok,
        public ?float $balance = null,
        public ?string $currency = null,
        public ?string $message = null,
        public ?ProviderErrorType $errorType = null,
        public array $raw = [],
    ) {}

    public function safeMessage(): string
    {
        if ($this->ok) {
            return $this->message ?: 'Balance retrieved from Termii.';
        }

        return $this->message ?: ($this->errorType?->label() ?? 'Unable to retrieve the Termii balance.');
    }
}
