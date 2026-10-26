<?php

namespace Tests\Support;

use App\Enums\DeliveryState;
use App\Enums\ProviderErrorType;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Termii\Data\BalanceResult;
use App\Services\Termii\Data\ProviderResult;
use App\Services\Termii\Data\ReportResult;
use App\Services\Termii\Data\SenderIdResult;
use App\Services\Termii\TermiiGatewayInterface;

/**
 * In-memory Termii gateway used by the test suite. It records every call so
 * tests can assert exactly what would have been sent, without any HTTP.
 */
class FakeTermiiGateway implements TermiiGatewayInterface
{
    /** @var array<int, array{phone: string, message: string, sender_id: string, channel: string, type: string}> */
    public array $singleSends = [];

    /** @var array<int, array{phones: array<int, string>, message: string, sender_id: string, channel: string, type: string}> */
    public array $bulkSends = [];

    public bool $configured = true;

    public ?ProviderResult $nextSingleResult = null;

    public ?ProviderResult $nextBulkResult = null;

    public ?BalanceResult $balanceResult = null;

    public ?SenderIdResult $senderIdResult = null;

    /** @var array<string, ReportResult> */
    public array $reports = [];

    private int $sequence = 0;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function maskedApiKey(): ?string
    {
        return $this->configured ? '••••••••test' : null;
    }

    public function sendSingle(
        string $phone,
        string $message,
        string $senderId,
        string $channel,
        string $type = 'plain',
        ?Campaign $campaign = null,
        ?CampaignRecipient $recipient = null,
    ): ProviderResult {
        $this->singleSends[] = [
            'phone' => $phone,
            'message' => $message,
            'sender_id' => $senderId,
            'channel' => $channel,
            'type' => $type,
        ];

        if ($this->nextSingleResult !== null) {
            return $this->nextSingleResult;
        }

        return ProviderResult::accepted('fake-single-'.$this->sequence++, 'Successfully sent', 200, 5);
    }

    public function sendBulk(
        array $phones,
        string $message,
        string $senderId,
        string $channel,
        string $type = 'plain',
        ?Campaign $campaign = null,
    ): ProviderResult {
        $this->bulkSends[] = [
            'phones' => $phones,
            'message' => $message,
            'sender_id' => $senderId,
            'channel' => $channel,
            'type' => $type,
        ];

        if ($this->nextBulkResult !== null) {
            return $this->nextBulkResult;
        }

        return ProviderResult::accepted('fake-bulk-'.$this->sequence++, 'Successfully sent', 200, 5);
    }

    public function balance(): BalanceResult
    {
        return $this->balanceResult ?? new BalanceResult(true, 1234.5, 'NGN', 'Balance retrieved.');
    }

    public function senderIds(?string $name = null): SenderIdResult
    {
        return $this->senderIdResult ?? new SenderIdResult(true, [
            ['id' => 'YAC', 'status' => 'active'],
        ], 'Sender IDs retrieved.');
    }

    public function messageReport(string $messageId): ReportResult
    {
        return $this->reports[$messageId] ?? new ReportResult(
            true,
            DeliveryState::Delivered,
            'Delivered',
            'Delivery report retrieved.',
        );
    }

    public function failNextBulk(ProviderErrorType $type, string $message = 'Provider error', ?int $status = 500): void
    {
        $this->nextBulkResult = ProviderResult::failed($type, $message, $status);
    }

    public function failNextSingle(ProviderErrorType $type, string $message = 'Provider error', ?int $status = 500): void
    {
        $this->nextSingleResult = ProviderResult::failed($type, $message, $status);
    }
}
