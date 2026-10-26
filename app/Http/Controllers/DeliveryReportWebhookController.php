<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryState;
use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ProviderWebhookEvent;
use App\Models\SmsLog;
use App\Services\Audit\AuditLogger;
use App\Services\Campaign\CampaignProgress;
use App\Services\Contacts\ContactService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Receives Termii delivery reports and inbound messages.
 *
 * Termii delivers a POST with the header "X-Termii-Signature", an HMAC-SHA512
 * of the raw body signed with the account secret (see
 * https://developers.termii.com/events-and-reports). The endpoint is disabled
 * unless TERMII_WEBHOOK_ENABLED=true and a secret is configured, so no
 * unsigned payload can ever change delivery state.
 *
 * The webhook URL must be configured in the Termii developer console.
 */
class DeliveryReportWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        CampaignProgress $progress,
        ContactService $contacts,
        SettingsService $settings,
        AuditLogger $audit,
    ): JsonResponse {
        $secret = (string) config('termii.webhook.secret');

        if (! config('termii.webhook.enabled') || $secret === '') {
            return response()->json(['message' => 'Webhook is disabled.'], 404);
        }

        $raw = $request->getContent();
        $expected = hash_hmac('sha512', $raw, $secret);
        $provided = $this->normalizeSignature((string) $request->header('X-Termii-Signature', ''));

        if ($provided === '' || ! hash_equals(strtolower($expected), $provided)) {
            ProviderWebhookEvent::create([
                'provider' => 'termii',
                'event_type' => 'unknown',
                'signature_valid' => false,
                'processed' => false,
                'processing_note' => 'Rejected: invalid or missing signature.',
                'payload_summary' => ['bytes' => strlen($raw)],
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            return response()->json(['message' => 'Empty payload.'], 422);
        }

        $type = strtolower((string) ($payload['type'] ?? ''));
        $eventType = match ($type) {
            'inbound' => 'inbound',
            'outbound' => 'outbound',
            default => 'unknown',
        };

        $messageId = $this->scalar($payload['message_id'] ?? null) ?? $this->scalar($payload['id'] ?? null);

        // For inbound messages the customer is the *sender*; for delivery
        // reports the customer is the receiver.
        $phoneSource = $type === 'inbound'
            ? ($payload['sender'] ?? $payload['from'] ?? $payload['receiver'] ?? null)
            : ($payload['receiver'] ?? $payload['to'] ?? null);

        $phone = $this->digits($phoneSource);
        $status = $this->scalar($payload['status'] ?? null) ?? $this->scalar($payload['command'] ?? null);
        $body = $this->scalar($payload['message'] ?? null);

        $dedupeKey = hash('sha256', implode('|', [
            $eventType,
            (string) $messageId,
            (string) $phone,
            (string) $status,
            (string) ($this->scalar($payload['sent_at'] ?? null) ?? $this->scalar($payload['received_at'] ?? null)),
            Str::limit((string) $body, 80),
        ]));

        $event = ProviderWebhookEvent::firstOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'provider' => 'termii',
                'event_type' => $eventType,
                'provider_message_id' => $messageId,
                'phone' => $phone,
                'status' => $status !== null ? Str::limit($status, 60, '') : null,
                'payload_summary' => array_filter([
                    'type' => $type !== '' ? $type : null,
                    'status' => $status,
                    'channel' => $this->scalar($payload['channel'] ?? null),
                    'sent_at' => $this->scalar($payload['sent_at'] ?? null),
                    'cost' => $this->scalar($payload['cost'] ?? null),
                    'message_excerpt' => $body !== null ? Str::limit($body, 120) : null,
                ], fn ($value) => $value !== null),
                'signature_valid' => true,
                'processed' => false,
            ],
        );

        if ($event->processed) {
            return response()->json(['message' => 'Already processed.']);
        }

        $note = match ($eventType) {
            'outbound' => $this->handleDeliveryReport($event, $payload, $progress, $audit),
            'inbound' => $this->handleInbound($event, $payload, $contacts, $settings, $audit),
            default => 'Unsupported event type.',
        };

        $event->forceFill([
            'processed' => true,
            'processing_note' => Str::limit($note, 250, ''),
        ])->save();

        return response()->json(['message' => 'Received.']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleDeliveryReport(
        ProviderWebhookEvent $event,
        array $payload,
        CampaignProgress $progress,
        AuditLogger $audit,
    ): string {
        $recipient = $this->findRecipient($event->provider_message_id, $event->phone);

        if ($recipient === null) {
            return 'No matching campaign recipient found.';
        }

        $state = DeliveryState::fromProviderStatus($event->status);
        $attributes = [
            'provider_status' => Str::limit((string) $event->status, 60, ''),
            'last_reconciled_at' => now(),
        ];

        switch ($state) {
            case DeliveryState::Delivered:
                $attributes['status'] = RecipientStatus::Delivered->value;
                $attributes['delivered_at'] = now();
                $attributes['error_code'] = null;
                $attributes['error_message'] = null;
                break;

            case DeliveryState::Failed:
                $attributes['status'] = RecipientStatus::Failed->value;
                $attributes['failed_at'] = now();
                $attributes['error_code'] = 'delivery_failed';
                $attributes['error_message'] = Str::limit(
                    sprintf('Termii reported "%s" for this message.', $event->status ?? 'failed'),
                    190,
                    '',
                );
                break;

            case DeliveryState::Sent:
            case DeliveryState::Pending:
                $attributes['status'] = RecipientStatus::Accepted->value;
                break;

            default:
                return 'Delivery status not recognised; recipient left unchanged.';
        }

        $recipient->forceFill($attributes)->save();

        SmsLog::create([
            'campaign_id' => $recipient->campaign_id,
            'campaign_recipient_id' => $recipient->id,
            'provider' => 'termii',
            'operation' => 'webhook',
            'endpoint' => 'webhooks/termii',
            'provider_message_id' => $recipient->provider_message_id,
            'response_meta' => ['status' => $event->status],
        ]);

        $campaign = Campaign::query()->find($recipient->campaign_id);

        if ($campaign !== null) {
            $progress->refresh($campaign);

            $audit->log('campaign.delivery_report', $campaign, sprintf(
                'Delivery report for %s: %s.',
                $recipient->phone,
                $event->status ?? 'unknown',
            ), ['recipient_id' => $recipient->id, 'status' => $event->status]);
        }

        return sprintf('Recipient %d updated to %s.', $recipient->id, $state->value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleInbound(
        ProviderWebhookEvent $event,
        array $payload,
        ContactService $contacts,
        SettingsService $settings,
        AuditLogger $audit,
    ): string {
        $text = trim((string) ($this->scalar($payload['message'] ?? null) ?? ''));
        $keywordText = strtoupper(preg_replace('/\s+/', ' ', $text) ?? '');
        $matched = null;

        foreach ($settings->optOutKeywords() as $keyword) {
            if ($keyword !== '' && ($keywordText === $keyword || str_starts_with($keywordText, $keyword.' '))) {
                $matched = $keyword;

                break;
            }
        }

        if ($matched === null) {
            return 'Inbound message recorded; no opt-out keyword matched.';
        }

        if ($event->phone === null) {
            return 'Opt-out keyword received without a sender number.';
        }

        $contact = Contact::query()->where('phone', $event->phone)->first();
        $reason = sprintf('Opt-out keyword "%s" received by SMS.', $matched);

        if ($contact !== null) {
            $contacts->unsubscribe($contact, null, $reason, 'inbound_sms');
        } else {
            $contacts->addSuppression($event->phone, null, $reason, 'inbound_sms');
        }

        $audit->log('consent.opt_out_inbound', null, sprintf('Number %s opted out by SMS keyword.', $event->phone), [
            'keyword' => $matched,
            'phone' => $event->phone,
        ]);

        return 'Number suppressed after an opt-out keyword.';
    }

    private function findRecipient(?string $messageId, ?string $phone): ?CampaignRecipient
    {
        if ($messageId !== null) {
            $recipient = CampaignRecipient::query()
                ->where('provider_message_id', $messageId)
                ->latest('id')
                ->first();

            if ($recipient !== null) {
                return $recipient;
            }
        }

        if ($phone === null) {
            return null;
        }

        return CampaignRecipient::query()
            ->where('phone', $phone)
            ->whereIn('status', [
                RecipientStatus::Accepted->value,
                RecipientStatus::Unknown->value,
                RecipientStatus::Processing->value,
            ])
            ->where('created_at', '>=', now()->subDays(7))
            ->latest('id')
            ->first();
    }

    private function normalizeSignature(string $header): string
    {
        $header = trim($header);

        if (str_contains($header, '=')) {
            [, $header] = explode('=', $header, 2);
        }

        return strtolower(trim($header));
    }

    private function scalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function digits(mixed $value): ?string
    {
        $value = $this->scalar($value);

        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : Str::limit($digits, 20, '');
    }
}
