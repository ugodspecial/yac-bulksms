<?php

namespace Tests\Feature;

use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ProviderWebhookEvent;
use App\Models\SuppressionEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSms;
use Tests\TestCase;

class TermiiWebhookTest extends TestCase
{
    use InteractsWithSms;
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'termii.webhook.enabled' => true,
            'termii.webhook.secret' => self::SECRET,
        ]);

        $this->fakeGateway();
        $this->configureSettings();
    }

    public function test_the_endpoint_is_hidden_when_the_webhook_is_disabled(): void
    {
        config(['termii.webhook.enabled' => false]);

        $this->postJson('/webhooks/termii', ['type' => 'outbound'], $this->signature(['type' => 'outbound']))
            ->assertNotFound();
    }

    public function test_a_payload_without_a_valid_signature_is_rejected(): void
    {
        $payload = $this->deliveryPayload('Delivered');

        $this->postJson('/webhooks/termii', $payload, ['X-Termii-Signature' => 'not-the-right-signature'])
            ->assertUnauthorized();

        $this->assertSame(0, CampaignRecipient::query()->where('status', RecipientStatus::Delivered->value)->count());
        $this->assertSame(1, ProviderWebhookEvent::query()->where('signature_valid', false)->count());
    }

    public function test_a_signed_delivery_report_updates_the_recipient(): void
    {
        $recipient = $this->acceptedRecipient('msg-abc-123', '2348031234567');

        $payload = $this->deliveryPayload('Delivered', 'msg-abc-123', '2348031234567');

        $this->postJson('/webhooks/termii', $payload, $this->signature($payload))->assertOk();

        $recipient->refresh();

        $this->assertSame(RecipientStatus::Delivered, $recipient->status);
        $this->assertNotNull($recipient->delivered_at);
        $this->assertSame('Delivered', $recipient->provider_status);
        $this->assertFalse($recipient->ambiguous);

        $event = ProviderWebhookEvent::query()->firstOrFail();
        $this->assertTrue($event->processed);
        $this->assertTrue($event->signature_valid);
        $this->assertSame('outbound', $event->event_type);
    }

    public function test_a_failed_delivery_report_marks_the_message_failed(): void
    {
        $recipient = $this->acceptedRecipient('msg-fail-1', '2348098765432');

        $payload = $this->deliveryPayload('Message Failed', 'msg-fail-1', '2348098765432');

        $this->postJson('/webhooks/termii', $payload, $this->signature($payload))->assertOk();

        $recipient->refresh();
        $this->assertSame(RecipientStatus::Failed, $recipient->status);
        $this->assertSame('delivery_failed', $recipient->error_code);
    }

    public function test_duplicate_webhook_deliveries_are_processed_only_once(): void
    {
        $recipient = $this->acceptedRecipient('msg-dup-1', '2348031234567');
        $payload = $this->deliveryPayload('Delivered', 'msg-dup-1', '2348031234567');

        $this->postJson('/webhooks/termii', $payload, $this->signature($payload))->assertOk();
        $this->postJson('/webhooks/termii', $payload, $this->signature($payload))->assertOk();

        $this->assertSame(1, ProviderWebhookEvent::query()->count());
        $this->assertSame(1, $recipient->campaign->smsLogs()->where('operation', 'webhook')->count());
    }

    public function test_a_delivery_report_without_a_known_message_is_recorded_but_not_applied(): void
    {
        $payload = $this->deliveryPayload('Delivered', 'msg-unknown', '2348000000000');

        $this->postJson('/webhooks/termii', $payload, $this->signature($payload))->assertOk();

        $this->assertSame(1, ProviderWebhookEvent::query()->count());
        $this->assertSame('Delivered', ProviderWebhookEvent::query()->firstOrFail()->status);
    }

    public function test_an_inbound_stop_message_unsubscribes_the_contact(): void
    {
        $contact = Contact::factory()->create(['phone' => '2348031234567']);

        $payload = [
            'type' => 'inbound',
            'id' => 'inbound-1',
            'sender' => '2348031234567',
            'receiver' => 'YAC',
            'message' => 'STOP',
            'received_at' => '2026-01-01T10:00:00Z',
        ];

        $this->postJson('/webhooks/termii', $payload, $this->signature($payload))->assertOk();

        $contact->refresh();

        $this->assertSame(\App\Enums\SubscriptionStatus::Unsubscribed, $contact->subscription_status);
        $this->assertTrue(SuppressionEntry::query()->where('phone', '2348031234567')->exists());
    }

    public function test_the_stored_webhook_payload_contains_no_api_key(): void
    {
        $payload = $this->deliveryPayload('Delivered', 'msg-key-1', '2348031234567');

        $this->postJson('/webhooks/termii', $payload, $this->signature($payload))->assertOk();

        $event = ProviderWebhookEvent::query()->firstOrFail();

        $this->assertStringNotContainsString('test-api-key-value', (string) json_encode($event->payload_summary));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function signature(array $payload): array
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return ['X-Termii-Signature' => hash_hmac('sha512', (string) $raw, self::SECRET)];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryPayload(string $status, string $messageId = 'msg-abc-123', string $receiver = '2348031234567'): array
    {
        return [
            'type' => 'outbound',
            'id' => 'event-'.$messageId,
            'message_id' => $messageId,
            'receiver' => $receiver,
            'sender' => 'YAC',
            'message' => 'Hello from YAC',
            'sent_at' => '2026-01-01T10:00:00Z',
            'cost' => 1,
            'status' => $status,
            'channel' => 'generic',
        ];
    }

    private function acceptedRecipient(string $messageId, string $phone): CampaignRecipient
    {
        $admin = $this->admin();
        $campaign = Campaign::factory()->create([
            'created_by' => $admin->id,
            'status' => 'processing',
            'confirmed_at' => now(),
            'queued_at' => now(),
        ]);

        return CampaignRecipient::factory()->accepted($messageId)->create([
            'campaign_id' => $campaign->id,
            'phone' => $phone,
        ]);
    }
}
