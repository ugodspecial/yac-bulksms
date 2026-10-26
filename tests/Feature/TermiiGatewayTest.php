<?php

namespace Tests\Feature;

use App\Enums\DeliveryState;
use App\Enums\ProviderErrorType;
use App\Models\SmsLog;
use App\Services\Termii\TermiiGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TermiiGatewayTest extends TestCase
{
    use RefreshDatabase;

    private TermiiGatewayInterface $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->app->make(TermiiGatewayInterface::class);
    }

    public function test_a_successful_send_is_recorded_as_accepted_with_a_message_id(): void
    {
        Http::fake([
            'v4.api.termii.com/api/sms/send' => Http::response([
                'message_id' => 'msg-8215',
                'message' => 'Successfully sent 1 message(s).',
                'balance' => 981.2,
                'user' => 'YAC SMS',
            ], 200),
        ]);

        $result = $this->gateway->sendSingle('2348031234567', 'Hello Ada', 'YAC', 'generic');

        $this->assertTrue($result->accepted);
        $this->assertSame('msg-8215', $result->messageId);
        $this->assertSame(200, $result->httpStatus);
        $this->assertFalse($result->isAmbiguous());

        Http::assertSent(function ($request) {
            return $request->url() === 'https://v4.api.termii.com/api/sms/send'
                && $request['to'] === '2348031234567'
                && $request['from'] === 'YAC'
                && $request['sms'] === 'Hello Ada'
                && $request['channel'] === 'generic'
                && $request['type'] === 'plain'
                && $request['api_key'] === 'test-api-key-value';
        });

        $log = SmsLog::query()->firstOrFail();

        $this->assertSame('send', $log->operation);
        $this->assertSame('msg-8215', $log->provider_message_id);
        $this->assertStringNotContainsString('test-api-key-value', (string) json_encode($log->request_meta));
        $this->assertStringNotContainsString('test-api-key-value', (string) $log->error_message);
        $this->assertStringNotContainsString('Hello Ada', (string) json_encode($log->request_meta));
    }

    public function test_invalid_credentials_are_classified_as_fatal(): void
    {
        Http::fake([
            'v4.api.termii.com/api/sms/send' => Http::response(['message' => 'Invalid API key'], 401),
        ]);

        $result = $this->gateway->sendSingle('2348031234567', 'Hello', 'YAC', 'generic');

        $this->assertFalse($result->accepted);
        $this->assertSame(ProviderErrorType::InvalidCredentials, $result->errorType);
        $this->assertTrue($result->isFatal());
        $this->assertFalse($result->isRetryable());
    }

    public function test_rate_limits_are_retryable(): void
    {
        Http::fake([
            'v4.api.termii.com/api/sms/send' => Http::response(['message' => 'Too many requests'], 429),
        ]);

        $result = $this->gateway->sendSingle('2348031234567', 'Hello', 'YAC', 'generic');

        $this->assertSame(ProviderErrorType::RateLimited, $result->errorType);
        $this->assertTrue($result->isRetryable());
        $this->assertFalse($result->isAmbiguous());
    }

    public function test_server_errors_are_retryable(): void
    {
        Http::fake([
            'v4.api.termii.com/api/sms/send' => Http::response(['message' => 'Service unavailable'], 503),
        ]);

        $result = $this->gateway->sendSingle('2348031234567', 'Hello', 'YAC', 'generic');

        $this->assertSame(ProviderErrorType::ServerError, $result->errorType);
        $this->assertTrue($result->isRetryable());
    }

    public function test_a_timeout_is_ambiguous_and_never_retried_automatically(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
        });

        $result = $this->gateway->sendSingle('2348031234567', 'Hello', 'YAC', 'generic');

        $this->assertFalse($result->accepted);
        $this->assertSame(ProviderErrorType::AmbiguousTimeout, $result->errorType);
        $this->assertTrue($result->isAmbiguous());
        $this->assertFalse($result->isRetryable());
    }

    public function test_a_connection_refusal_is_a_retryable_network_error(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect to v4.api.termii.com');
        });

        $result = $this->gateway->sendSingle('2348031234567', 'Hello', 'YAC', 'generic');

        $this->assertSame(ProviderErrorType::NetworkError, $result->errorType);
        $this->assertTrue($result->isRetryable());
    }

    public function test_insufficient_balance_is_detected_even_on_http_200(): void
    {
        Http::fake([
            'v4.api.termii.com/api/sms/send' => Http::response([
                'message' => 'Insufficient balance, please fund your account',
            ], 200),
        ]);

        $result = $this->gateway->sendSingle('2348031234567', 'Hello', 'YAC', 'generic');

        $this->assertSame(ProviderErrorType::InsufficientBalance, $result->errorType);
        $this->assertTrue($result->isFatal());
    }

    public function test_bulk_sends_are_limited_to_one_hundred_recipients(): void
    {
        Http::fake();

        $phones = array_map(fn ($index) => '234803123'.str_pad((string) $index, 4, '0', STR_PAD_LEFT), range(1, 101));

        $result = $this->gateway->sendBulk($phones, 'Hello', 'YAC', 'generic');

        $this->assertFalse($result->accepted);
        $this->assertSame(ProviderErrorType::InvalidPayload, $result->errorType);
        Http::assertNothingSent();
    }

    public function test_balance_is_parsed_from_the_provider(): void
    {
        Http::fake([
            'v4.api.termii.com/api/get-balance*' => Http::response([
                'balance' => 550.75,
                'currency' => 'NGN',
                'user' => 'YAC SMS',
            ], 200),
        ]);

        $result = $this->gateway->balance();

        $this->assertTrue($result->ok);
        $this->assertEqualsWithDelta(550.75, $result->balance, 0.001);
        $this->assertSame('NGN', $result->currency);
    }

    public function test_delivery_reports_are_mapped_to_delivery_states(): void
    {
        Http::fake([
            'v4.api.termii.com/api/sms/inbox*' => Http::response([
                [
                    'message_id' => 'msg-8215',
                    'status' => 'Delivered',
                    'receiver' => '2348031234567',
                ],
            ], 200),
        ]);

        $result = $this->gateway->messageReport('msg-8215');

        $this->assertTrue($result->ok);
        $this->assertSame(DeliveryState::Delivered, $result->state);
        $this->assertSame('Delivered', $result->providerStatus);
    }

    public function test_the_masked_api_key_never_contains_the_real_key(): void
    {
        $masked = $this->gateway->maskedApiKey();

        $this->assertNotNull($masked);
        $this->assertStringNotContainsString('test-api-key-value', (string) $masked);
        $this->assertStringEndsWith('alue', (string) $masked);
    }
}
