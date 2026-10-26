<?php

namespace App\Services\Termii\Data;

use App\Enums\ProviderErrorType;

final readonly class SenderIdResult
{
    /**
     * @param  array<int, array{id: string, status: string|null}>  $senderIds
     */
    public function __construct(
        public bool $ok,
        public array $senderIds = [],
        public ?string $message = null,
        public ?ProviderErrorType $errorType = null,
        public array $raw = [],
    ) {}

    public function find(string $senderId): ?array
    {
        foreach ($this->senderIds as $entry) {
            if (strcasecmp($entry['id'], $senderId) === 0) {
                return $entry;
            }
        }

        return null;
    }

    public function safeMessage(): string
    {
        if ($this->ok) {
            return $this->message ?: sprintf('%d sender ID(s) available on the Termii account.', count($this->senderIds));
        }

        return $this->message ?: ($this->errorType?->label() ?? 'Unable to retrieve sender IDs.');
    }
}
