<?php

namespace App\Services\Provider;

use App\Enums\ProviderErrorType;
use App\Models\ProviderStatus;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsService;
use App\Services\Termii\Data\BalanceResult;
use App\Services\Termii\TermiiGatewayInterface;
use Illuminate\Support\Str;

/**
 * Tracks the health of the shared Termii account: connection state, balance,
 * sender-ID validity and the sanitised last provider error.
 */
class ProviderHealthService
{
    public function __construct(
        private readonly TermiiGatewayInterface $gateway,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function status(): ProviderStatus
    {
        return ProviderStatus::termii();
    }

    public function checkBalance(?User $actor = null): BalanceResult
    {
        $result = $this->gateway->balance();
        $status = $this->status();

        if ($result->ok) {
            $status->fill([
                'status' => 'connected',
                'balance' => $result->balance,
                'currency' => $result->currency ?: 'NGN',
                'last_balance_at' => now(),
                'last_checked_at' => now(),
            ])->save();
        } else {
            $status->fill([
                'status' => 'error',
                'last_checked_at' => now(),
                'last_error' => Str::limit($result->safeMessage(), 250),
                'last_error_type' => $result->errorType?->value ?? ProviderErrorType::Unknown->value,
                'last_error_at' => now(),
            ])->save();
        }

        if ($actor !== null) {
            $this->audit->log(
                'provider.balance_checked',
                $status,
                $result->ok ? 'Checked the shared Termii account balance.' : 'A Termii balance check failed.',
                ['ok' => $result->ok, 'error_type' => $result->errorType?->value],
                $actor,
            );
        }

        return $result;
    }

    /**
     * Full integration test: balance + sender ID status. Never returns secrets.
     *
     * @return array{ok: bool, balance: BalanceResult, sender: array{ok: bool, status: string|null, message: string}}
     */
    public function testConnection(?User $actor = null): array
    {
        $balance = $this->checkBalance($actor);
        $sender = $this->validateSenderId($this->settings->defaultSenderId(), $actor);
        $status = $this->status();

        $status->fill(['status' => $balance->ok && $sender['ok'] ? 'connected' : 'error'])->save();

        return ['ok' => $balance->ok && $sender['ok'], 'balance' => $balance, 'sender' => $sender];
    }

    /**
     * @return array{ok: bool, status: string|null, message: string}
     */
    public function validateSenderId(?string $senderId, ?User $actor = null): array
    {
        $status = $this->status();

        if ($senderId === null || trim($senderId) === '') {
            $message = 'No sender ID is configured. Set one in Settings before sending campaigns.';

            $status->fill([
                'sender_id' => null,
                'sender_status' => 'missing',
                'sender_message' => $message,
                'last_checked_at' => now(),
            ])->save();

            return ['ok' => false, 'status' => 'missing', 'message' => $message];
        }

        $result = $this->gateway->senderIds();

        if (! $result->ok) {
            $message = 'Could not verify the sender ID: '.$result->safeMessage();

            $status->fill([
                'sender_id' => $senderId,
                'sender_status' => 'unknown',
                'sender_message' => Str::limit($message, 250),
                'last_checked_at' => now(),
                'last_error' => Str::limit($result->safeMessage(), 250),
                'last_error_type' => $result->errorType?->value,
                'last_error_at' => now(),
            ])->save();

            return ['ok' => false, 'status' => 'unknown', 'message' => $message];
        }

        $entry = $result->find($senderId);

        if ($entry === null) {
            $message = sprintf('The sender ID "%s" was not found on the Termii account. Request or verify it in Termii before sending.', $senderId);

            $status->fill([
                'sender_id' => $senderId,
                'sender_status' => 'not_found',
                'sender_message' => Str::limit($message, 250),
                'last_checked_at' => now(),
            ])->save();

            return ['ok' => false, 'status' => 'not_found', 'message' => $message];
        }

        $providerStatus = strtolower((string) $entry['status']);
        $ok = $providerStatus === '' || str_contains($providerStatus, 'active');

        $message = $ok
            ? sprintf('Sender ID "%s" is active on Termii.', $senderId)
            : sprintf('Sender ID "%s" is not active (Termii status: %s).', $senderId, $entry['status'] ?? 'unknown');

        $status->fill([
            'sender_id' => $senderId,
            'sender_status' => $providerStatus !== '' ? $providerStatus : 'unknown',
            'sender_message' => Str::limit($message, 250),
            'last_checked_at' => now(),
        ])->save();

        if ($actor !== null) {
            $this->audit->log('provider.sender_checked', $status, $message, [
                'ok' => $ok,
                'sender_status' => $providerStatus,
            ], $actor);
        }

        return ['ok' => $ok, 'status' => $providerStatus, 'message' => $message];
    }

    public function recordSendFailure(ProviderErrorType $type, string $message): void
    {
        $this->status()->fill([
            'status' => 'error',
            'last_error' => Str::limit($message, 250),
            'last_error_type' => $type->value,
            'last_error_at' => now(),
            'last_checked_at' => now(),
        ])->save();
    }

    public function recordSendSuccess(?float $balance = null): void
    {
        $status = $this->status();
        $attributes = ['status' => 'connected', 'last_checked_at' => now()];

        if ($balance !== null) {
            $attributes['balance'] = $balance;
            $attributes['last_balance_at'] = now();
        }

        $status->fill($attributes)->save();
    }
}
