<?php

namespace App\Services\Termii;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Termii\Data\BalanceResult;
use App\Services\Termii\Data\ProviderResult;
use App\Services\Termii\Data\ReportResult;
use App\Services\Termii\Data\SenderIdResult;

/**
 * The single boundary between YAC-BulkSMS and the Termii gateway. Every
 * provider call in the application goes through this interface, which makes
 * it trivial to fake in tests and to isolate provider-specific behaviour.
 */
interface TermiiGatewayInterface
{
    public function isConfigured(): bool;

    /** Masked API key for the admin settings screen (never the full key). */
    public function maskedApiKey(): ?string;

    public function sendSingle(
        string $phone,
        string $message,
        string $senderId,
        string $channel,
        string $type = 'plain',
        ?Campaign $campaign = null,
        ?CampaignRecipient $recipient = null,
    ): ProviderResult;

    /**
     * @param  array<int, string>  $phones  at most 100 numbers
     */
    public function sendBulk(
        array $phones,
        string $message,
        string $senderId,
        string $channel,
        string $type = 'plain',
        ?Campaign $campaign = null,
    ): ProviderResult;

    public function balance(): BalanceResult;

    public function senderIds(?string $name = null): SenderIdResult;

    public function messageReport(string $messageId): ReportResult;
}
