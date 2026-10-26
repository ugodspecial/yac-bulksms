<?php

namespace App\Services\Termii\Data;

use App\Enums\ProviderErrorType;

/**
 * Outcome of a send request. "Accepted" only means Termii accepted the
 * request - never that the message reached the handset.
 */
final readonly class ProviderResult
{
    public function __construct(
        public bool $accepted,
        public ?string $messageId,
        public ?string $providerMessage,
        public ?ProviderErrorType $errorType,
        public ?int $httpStatus,
        public int $durationMs,
        public array $response = [],
    ) {}

    public static function accepted(
        ?string $messageId,
        ?string $message,
        ?int $httpStatus,
        int $durationMs,
        array $response = [],
    ): self {
        return new self(true, $messageId, $message, null, $httpStatus, $durationMs, $response);
    }

    public static function failed(
        ProviderErrorType $type,
        string $message,
        ?int $httpStatus = null,
        int $durationMs = 0,
        array $response = [],
        ?string $messageId = null,
    ): self {
        return new self(false, $messageId, $message, $type, $httpStatus, $durationMs, $response);
    }

    public function isRetryable(): bool
    {
        return ! $this->accepted && ($this->errorType?->isRetryable() ?? false);
    }

    public function isAmbiguous(): bool
    {
        return ! $this->accepted && ($this->errorType?->isAmbiguous() ?? false);
    }

    public function isFatal(): bool
    {
        return ! $this->accepted && ($this->errorType?->isFatal() ?? false);
    }

    public function safeMessage(): string
    {
        if (filled($this->providerMessage)) {
            return (string) $this->providerMessage;
        }

        return $this->errorType?->label() ?? 'Unknown provider response';
    }
}
