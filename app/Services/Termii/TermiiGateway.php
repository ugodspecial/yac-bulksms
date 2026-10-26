<?php

namespace App\Services\Termii;

use App\Enums\DeliveryState;
use App\Enums\ProviderErrorType;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\SmsLog;
use App\Services\Termii\Data\BalanceResult;
use App\Services\Termii\Data\ProviderResult;
use App\Services\Termii\Data\ReportResult;
use App\Services\Termii\Data\SenderIdResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Zeevx\LaraTermii\Exceptions\TermiiException;
use Zeevx\LaraTermii\LaraTermii;

/**
 * Termii adapter built on top of zeevx/lara-termii (v1.3).
 *
 * Methods used from the package (verified against its source):
 *   - sendMessage(to, from, sms, channel, mediaUrl, mediaCaption, type)
 *   - sendBulkMessage(to[], from, sms, channel, type)      (max 100 numbers)
 *   - balance()
 *   - senderIds: allSenderId(?name, ?status)
 *   - messageReport: history(?messageId)
 *
 * All calls return an Illuminate\Http\Client\Response, which lets us record
 * the HTTP status and the provider's own result code instead of assuming a
 * 200 means "delivered".
 */
class TermiiGateway implements TermiiGatewayInterface
{
    private const ENDPOINT_SEND = 'sms/send';

    private const ENDPOINT_BULK = 'sms/send/bulk';

    private const ENDPOINT_BALANCE = 'get-balance';

    private const ENDPOINT_SENDER_IDS = 'sender-id';

    private const ENDPOINT_HISTORY = 'sms/inbox';

    public function isConfigured(): bool
    {
        return filled(config('termii.api_key'));
    }

    public function maskedApiKey(): ?string
    {
        $key = trim((string) config('termii.api_key'));

        if ($key === '') {
            return null;
        }

        return str_repeat('•', 8).substr($key, -4);
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
        $start = microtime(true);
        $meta = [
            'to' => $phone,
            'from' => $senderId,
            'channel' => $channel,
            'type' => $type,
            'sms_length' => mb_strlen($message),
        ];

        if (! $this->isConfigured()) {
            return $this->notConfigured($start, $meta, $campaign, $recipient, self::ENDPOINT_SEND);
        }

        try {
            $response = $this->client()->sendMessage(
                to: $phone,
                from: $senderId,
                sms: $message,
                channel: $channel,
                type: $type,
            );
        } catch (ConnectionException $e) {
            return $this->connectionFailure($e, $start, $meta, $campaign, $recipient, self::ENDPOINT_SEND);
        } catch (TermiiException $e) {
            return $this->notConfigured($start, $meta, $campaign, $recipient, self::ENDPOINT_SEND, $e->getMessage());
        } catch (Throwable $e) {
            return $this->unexpectedFailure($e, $start, $meta, $campaign, $recipient, self::ENDPOINT_SEND);
        }

        $result = $this->interpretSend($response, microtime(true) - $start);

        $this->log(
            operation: 'send',
            endpoint: self::ENDPOINT_SEND,
            status: $response->status(),
            durationMs: $result->durationMs,
            request: $meta,
            response: $this->responseSummary($response),
            result: $result,
            campaign: $campaign,
            recipient: $recipient,
        );

        return $result;
    }

    public function sendBulk(
        array $phones,
        string $message,
        string $senderId,
        string $channel,
        string $type = 'plain',
        ?Campaign $campaign = null,
    ): ProviderResult {
        $start = microtime(true);
        $meta = [
            'to' => array_values($phones),
            'recipient_count' => count($phones),
            'from' => $senderId,
            'channel' => $channel,
            'type' => $type,
            'sms_length' => mb_strlen($message),
        ];

        if (! $this->isConfigured()) {
            return $this->notConfigured($start, $meta, $campaign, null, self::ENDPOINT_BULK);
        }

        if (count($phones) > 100) {
            return ProviderResult::failed(
                ProviderErrorType::InvalidPayload,
                'Termii accepts at most 100 recipients per bulk request.',
                null,
                (int) round((microtime(true) - $start) * 1000),
            );
        }

        try {
            $response = $this->client()->sendBulkMessage(
                to: array_values($phones),
                from: $senderId,
                sms: $message,
                channel: $channel,
                type: $type,
            );
        } catch (ConnectionException $e) {
            return $this->connectionFailure($e, $start, $meta, $campaign, null, self::ENDPOINT_BULK);
        } catch (TermiiException $e) {
            return $this->notConfigured($start, $meta, $campaign, null, self::ENDPOINT_BULK, $e->getMessage());
        } catch (Throwable $e) {
            return $this->unexpectedFailure($e, $start, $meta, $campaign, null, self::ENDPOINT_BULK);
        }

        $result = $this->interpretSend($response, microtime(true) - $start);

        $this->log(
            operation: 'send_bulk',
            endpoint: self::ENDPOINT_BULK,
            status: $response->status(),
            durationMs: $result->durationMs,
            request: $meta,
            response: $this->responseSummary($response),
            result: $result,
            campaign: $campaign,
        );

        return $result;
    }

    public function balance(): BalanceResult
    {
        $start = microtime(true);

        if (! $this->isConfigured()) {
            $this->logFailure('balance', self::ENDPOINT_BALANCE, ProviderErrorType::NotConfigured, 'Termii API key is not configured.', $start);

            return new BalanceResult(false, message: 'Termii API key is not configured.', errorType: ProviderErrorType::NotConfigured);
        }

        try {
            $response = $this->client()->balance();
        } catch (ConnectionException $e) {
            return $this->balanceFailure($e, $start);
        } catch (Throwable $e) {
            return $this->balanceFailure($e, $start);
        }

        $duration = (int) round((microtime(true) - $start) * 1000);
        $json = $this->json($response);
        $errorType = $this->classify($response, $json);

        if (! $response->successful() || $errorType !== null) {
            $type = $errorType ?? ProviderErrorType::Unknown;
            $message = $this->safeMessage($json, $type);

            $this->log('balance', self::ENDPOINT_BALANCE, $response->status(), $duration, [], $this->responseSummary($response), ProviderResult::failed($type, $message, $response->status(), $duration));

            return new BalanceResult(false, message: $message, errorType: $type, raw: $this->responseSummary($response));
        }

        $balance = $json['balance'] ?? ($json['data']['balance'] ?? null);
        $currency = $json['currency'] ?? ($json['data']['currency'] ?? null);

        $this->log('balance', self::ENDPOINT_BALANCE, $response->status(), $duration, [], $this->responseSummary($response), ProviderResult::accepted(null, 'Balance retrieved.', $response->status(), $duration));

        return new BalanceResult(
            true,
            balance: is_numeric($balance) ? (float) $balance : null,
            currency: is_scalar($currency) ? (string) $currency : null,
            message: 'Balance retrieved from Termii.',
            raw: $this->responseSummary($response),
        );
    }

    public function senderIds(?string $name = null): SenderIdResult
    {
        $start = microtime(true);

        if (! $this->isConfigured()) {
            return new SenderIdResult(false, message: 'Termii API key is not configured.', errorType: ProviderErrorType::NotConfigured);
        }

        try {
            $response = $this->client()->allSenderId($name);
        } catch (ConnectionException $e) {
            $type = $this->classifyConnectionException($e);

            return new SenderIdResult(false, message: $this->connectionMessage($type), errorType: $type);
        } catch (Throwable $e) {
            Log::warning('Termii sender ID lookup failed', ['error' => $this->sanitize($e->getMessage())]);

            return new SenderIdResult(false, message: 'Unable to reach Termii.', errorType: ProviderErrorType::NetworkError);
        }

        $duration = (int) round((microtime(true) - $start) * 1000);
        $json = $this->json($response);
        $errorType = $this->classify($response, $json);

        if (! $response->successful() || $errorType !== null) {
            $type = $errorType ?? ProviderErrorType::Unknown;
            $message = $this->safeMessage($json, $type);

            $this->log('sender_ids', self::ENDPOINT_SENDER_IDS, $response->status(), $duration, [], $this->responseSummary($response), ProviderResult::failed($type, $message, $response->status(), $duration));

            return new SenderIdResult(false, message: $message, errorType: $type, raw: $this->responseSummary($response));
        }

        $entries = [];

        foreach ($this->firstList($json) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['sender_id'] ?? $item['senderId'] ?? $item['name'] ?? null;

            if (! is_scalar($id) || trim((string) $id) === '') {
                continue;
            }

            $status = $item['status'] ?? $item['sender_id_status'] ?? null;

            $entries[] = [
                'id' => (string) $id,
                'status' => is_scalar($status) ? (string) $status : null,
            ];
        }

        $this->log('sender_ids', self::ENDPOINT_SENDER_IDS, $response->status(), $duration, [], ['count' => count($entries)], ProviderResult::accepted(null, 'Sender IDs retrieved.', $response->status(), $duration));

        return new SenderIdResult(true, $entries, 'Sender IDs retrieved from Termii.', null, $this->responseSummary($response));
    }

    public function messageReport(string $messageId): ReportResult
    {
        $start = microtime(true);

        if (! $this->isConfigured()) {
            return new ReportResult(false, message: 'Termii API key is not configured.', errorType: ProviderErrorType::NotConfigured);
        }

        try {
            $response = $this->client()->history($messageId);
        } catch (ConnectionException $e) {
            $type = $this->classifyConnectionException($e);

            return new ReportResult(false, message: $this->connectionMessage($type), errorType: $type);
        } catch (Throwable $e) {
            Log::warning('Termii message report lookup failed', ['error' => $this->sanitize($e->getMessage())]);

            return new ReportResult(false, message: 'Unable to reach Termii.', errorType: ProviderErrorType::NetworkError);
        }

        $duration = (int) round((microtime(true) - $start) * 1000);
        $json = $this->json($response);
        $errorType = $this->classify($response, $json);

        if (! $response->successful() || $errorType !== null) {
            $type = $errorType ?? ProviderErrorType::Unknown;
            $message = $this->safeMessage($json, $type);

            $this->log('history', self::ENDPOINT_HISTORY, $response->status(), $duration, ['message_id' => $messageId], $this->responseSummary($response), ProviderResult::failed($type, $message, $response->status(), $duration));

            return new ReportResult(false, message: $message, errorType: $type, raw: $this->responseSummary($response));
        }

        $report = $this->findReport($json, $messageId);
        $status = $report['status'] ?? $report['delivery_status'] ?? null;
        $state = DeliveryState::fromProviderStatus(is_scalar($status) ? (string) $status : null);

        $this->log('history', self::ENDPOINT_HISTORY, $response->status(), $duration, ['message_id' => $messageId], $this->responseSummary($response), ProviderResult::accepted(null, 'Report retrieved.', $response->status(), $duration));

        return new ReportResult(
            true,
            state: $state,
            providerStatus: is_scalar($status) ? (string) $status : null,
            message: $report === [] ? 'No report found for this message yet.' : 'Delivery report retrieved.',
            raw: $this->responseSummary($response),
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function client(): LaraTermii
    {
        return new LaraTermii(
            apiKey: (string) config('termii.api_key'),
            baseUrl: (string) config('termii.base_url', 'https://v4.api.termii.com'),
            senderId: config('termii.sender_id') ?: null,
            channel: (string) config('termii.channel', 'generic'),
            timeout: (int) config('termii.timeout', 30),
            throw: false,
        );
    }

    private function interpretSend(Response $response, float $seconds): ProviderResult
    {
        $duration = (int) round($seconds * 1000);
        $json = $this->json($response);
        $errorType = $this->classify($response, $json);

        if (! $response->successful() || $errorType !== null) {
            $type = $errorType ?? ProviderErrorType::Unknown;

            return ProviderResult::failed(
                $type,
                $this->safeMessage($json, $type),
                $response->status(),
                $duration,
                $this->responseSummary($response),
                $this->messageId($json),
            );
        }

        return ProviderResult::accepted(
            $this->messageId($json),
            $this->safeMessage($json, null),
            $response->status(),
            $duration,
            $this->responseSummary($response),
        );
    }

    private function notConfigured(
        float $start,
        array $meta,
        ?Campaign $campaign,
        ?CampaignRecipient $recipient,
        string $endpoint,
        ?string $message = null,
    ): ProviderResult {
        $result = ProviderResult::failed(
            ProviderErrorType::NotConfigured,
            $message ?: 'Termii API key is not configured.',
            null,
            (int) round((microtime(true) - $start) * 1000),
        );

        $this->log('send', $endpoint, null, $result->durationMs, $meta, [], $result, $campaign, $recipient);

        return $result;
    }

    private function connectionFailure(
        ConnectionException $e,
        float $start,
        array $meta,
        ?Campaign $campaign,
        ?CampaignRecipient $recipient,
        string $endpoint,
    ): ProviderResult {
        $type = $this->classifyConnectionException($e);
        $result = ProviderResult::failed(
            $type,
            $this->connectionMessage($type),
            null,
            (int) round((microtime(true) - $start) * 1000),
        );

        $this->log('send', $endpoint, null, $result->durationMs, $meta, [], $result, $campaign, $recipient);
        Log::warning('Termii connection error', [
            'type' => $type->value,
            'error' => $this->sanitize($e->getMessage()),
        ]);

        return $result;
    }

    private function unexpectedFailure(
        Throwable $e,
        float $start,
        array $meta,
        ?Campaign $campaign,
        ?CampaignRecipient $recipient,
        string $endpoint,
    ): ProviderResult {
        $result = ProviderResult::failed(
            ProviderErrorType::Unknown,
            'An unexpected error occurred while contacting Termii.',
            null,
            (int) round((microtime(true) - $start) * 1000),
        );

        $this->log('send', $endpoint, null, $result->durationMs, $meta, [], $result, $campaign, $recipient);
        Log::error('Unexpected Termii error', ['error' => $this->sanitize($e->getMessage())]);

        return $result;
    }

    private function balanceFailure(Throwable $e, float $start): BalanceResult
    {
        $type = $e instanceof ConnectionException
            ? $this->classifyConnectionException($e)
            : ProviderErrorType::Unknown;

        $message = $type === ProviderErrorType::AmbiguousTimeout
            ? $this->connectionMessage($type)
            : 'Unable to retrieve the Termii balance.';

        $this->logFailure('balance', self::ENDPOINT_BALANCE, $type, $message, $start);
        Log::warning('Termii balance lookup failed', ['type' => $type->value, 'error' => $this->sanitize($e->getMessage())]);

        return new BalanceResult(false, message: $message, errorType: $type);
    }

    /**
     * Classify a failed response by HTTP status and provider message.
     */
    private function classify(Response $response, array $json): ?ProviderErrorType
    {
        $status = $response->status();
        $text = strtolower(trim($this->rawText($json).' '.$response->body()));

        if ($response->successful()) {
            foreach (['insufficient balance', 'insufficient fund', 'low balance', 'not enough balance'] as $needle) {
                if (str_contains($text, $needle)) {
                    return ProviderErrorType::InsufficientBalance;
                }
            }

            foreach (['"code":"error"', '"status":"error"', '"status":"failed"'] as $needle) {
                if (str_contains(str_replace(' ', '', $text), str_replace(' ', '', $needle))) {
                    return ProviderErrorType::Unknown;
                }
            }

            return null;
        }

        if ($status === 401 || $status === 403) {
            return ProviderErrorType::InvalidCredentials;
        }

        if ($status === 429 || str_contains($text, 'rate limit') || str_contains($text, 'too many')) {
            return ProviderErrorType::RateLimited;
        }

        if ($status >= 500) {
            return ProviderErrorType::ServerError;
        }

        if (str_contains($text, 'api key') || str_contains($text, 'unauthorized') || str_contains($text, 'authenticat')) {
            return ProviderErrorType::InvalidCredentials;
        }

        if (str_contains($text, 'insufficient') || str_contains($text, 'balance')) {
            return ProviderErrorType::InsufficientBalance;
        }

        if (str_contains($text, 'sender') || str_contains($text, 'invalid from')) {
            return ProviderErrorType::InvalidSenderId;
        }

        if (str_contains($text, 'phone') || str_contains($text, 'number') || str_contains($text, 'recipient')) {
            return ProviderErrorType::InvalidRecipient;
        }

        if ($status >= 400) {
            return ProviderErrorType::InvalidPayload;
        }

        return ProviderErrorType::Unknown;
    }

    /**
     * Timeouts and dropped connections after the request was written are
     * ambiguous: the provider may have accepted the message, so they are
     * never retried automatically. DNS/connection failures are safe to retry.
     */
    private function classifyConnectionException(ConnectionException $e): ProviderErrorType
    {
        if (preg_match('/cURL error (\d+)/i', $e->getMessage(), $matches) === 1) {
            $code = (int) $matches[1];

            // 28: timeout, 52: empty reply, 55/56: send/recv failure.
            if (in_array($code, [28, 52, 55, 56], true)) {
                return ProviderErrorType::AmbiguousTimeout;
            }

            return ProviderErrorType::NetworkError;
        }

        if (str_contains(strtolower($e->getMessage()), 'timed out')) {
            return ProviderErrorType::AmbiguousTimeout;
        }

        return ProviderErrorType::NetworkError;
    }

    private function connectionMessage(ProviderErrorType $type): string
    {
        return $type === ProviderErrorType::AmbiguousTimeout
            ? 'The request to Termii timed out and the outcome is unknown. It was not retried automatically to avoid duplicate messages.'
            : 'Could not reach Termii. The request failed before being sent and is safe to retry.';
    }

    private function safeMessage(array $json, ?ProviderErrorType $type): string
    {
        $message = $json['message'] ?? $json['error'] ?? null;

        if (! is_scalar($message) || trim((string) $message) === '') {
            $message = $type?->label();
        }

        return Str::limit($this->sanitize((string) $message), 240) ?: 'Unknown provider response';
    }

    private function messageId(array $json): ?string
    {
        $id = $json['message_id'] ?? $json['messageId'] ?? ($json['data']['message_id'] ?? null);

        if (! is_scalar($id)) {
            return null;
        }

        $id = trim((string) $id);

        return $id !== '' ? Str::limit($id, 64, '') : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        try {
            $json = $response->json();
        } catch (Throwable) {
            return [];
        }

        return is_array($json) ? $json : [];
    }

    /**
     * Termii responses wrap lists in different keys across endpoints; return
     * the first list of arrays we can find.
     *
     * @return array<int, mixed>
     */
    private function firstList(mixed $data, int $depth = 0): array
    {
        if (! is_array($data) || $depth > 3) {
            return [];
        }

        if (array_is_list($data)) {
            return $data;
        }

        foreach (['content', 'data', 'sender_ids', 'senderIds', 'results'] as $key) {
            if (isset($data[$key])) {
                $candidate = $this->firstList($data[$key], $depth + 1);

                if ($candidate !== []) {
                    return $candidate;
                }
            }
        }

        return [];
    }

    /**
     * Find the report entry for a message id.
     *
     * @return array<string, mixed>
     */
    private function findReport(mixed $data, string $messageId): array
    {
        $candidates = $this->firstList($data);

        if ($candidates === [] && is_array($data)) {
            $candidates = [$data];
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $id = $candidate['message_id'] ?? $candidate['id'] ?? null;

            if (is_scalar($id) && (string) $id === $messageId) {
                return $candidate;
            }
        }

        return $candidates[0] ?? [];
    }

    /**
     * Redacted request/response summary stored in sms_logs.
     *
     * @return array<string, mixed>
     */
    private function responseSummary(Response $response): array
    {
        $json = $this->json($response);
        $summary = array_filter([
            'code' => is_scalar($json['code'] ?? null) ? $json['code'] : null,
            'message' => isset($json['message']) && is_scalar($json['message']) ? Str::limit((string) $json['message'], 200) : null,
            'message_id' => $this->messageId($json),
            'balance' => is_numeric($json['balance'] ?? null) ? $json['balance'] : null,
            'user' => is_scalar($json['user'] ?? null) ? Str::limit((string) $json['user'], 100) : null,
        ], fn ($value) => $value !== null);

        if ($summary === [] && $response->failed()) {
            $summary['body'] = Str::limit($this->sanitize($response->body()), 200);
        }

        return $summary;
    }

    private function rawText(array $json): string
    {
        $text = json_encode($json);

        return $text !== false ? $text : '';
    }

    private function sanitize(string $value): string
    {
        $key = trim((string) config('termii.api_key'));

        if ($key !== '') {
            $value = str_replace($key, '[redacted]', $value);
        }

        $value = preg_replace('/api[_-]?key["\']?\s*[:=]\s*["\']?[A-Za-z0-9._-]+/i', 'api_key=[redacted]', $value) ?? $value;

        return $value;
    }

    private function logFailure(string $operation, string $endpoint, ProviderErrorType $type, string $message, float $start): void
    {
        $this->log(
            $operation,
            $endpoint,
            null,
            (int) round((microtime(true) - $start) * 1000),
            [],
            [],
            ProviderResult::failed($type, $message),
        );
    }

    private function log(
        string $operation,
        string $endpoint,
        ?int $status,
        int $durationMs,
        array $request,
        array $response,
        ProviderResult $result,
        ?Campaign $campaign = null,
        ?CampaignRecipient $recipient = null,
    ): void {
        try {
            SmsLog::create([
                'campaign_id' => $campaign?->id ?? $recipient?->campaign_id,
                'campaign_recipient_id' => $recipient?->id,
                'provider' => 'termii',
                'operation' => $operation,
                'endpoint' => $endpoint,
                'http_status' => $status,
                'duration_ms' => $durationMs,
                'request_meta' => $request === [] ? null : $request,
                'response_meta' => $response === [] ? null : $response,
                'provider_message_id' => $result->messageId,
                'error_type' => $result->errorType?->value,
                'error_message' => $result->accepted ? null : $this->sanitize($result->safeMessage()),
            ]);
        } catch (Throwable $e) {
            Log::warning('Unable to persist Termii sms log: '.$e->getMessage());
        }
    }
}
