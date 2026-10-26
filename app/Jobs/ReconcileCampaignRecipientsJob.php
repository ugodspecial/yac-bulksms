<?php

namespace App\Jobs;

use App\Enums\DeliveryState;
use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Campaign\CampaignProgress;
use App\Services\Termii\TermiiGatewayInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Polls Termii's message history endpoint for accepted messages that have a
 * provider message id, and updates the local delivery state.
 */
class ReconcileCampaignRecipientsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  array<int, int>  $recipientIds
     */
    public function __construct(public readonly array $recipientIds) {}

    public function handle(TermiiGatewayInterface $gateway, CampaignProgress $progress): void
    {
        /** @var Collection<int, CampaignRecipient> $recipients */
        $recipients = CampaignRecipient::query()
            ->whereIn('id', $this->recipientIds)
            ->where('status', RecipientStatus::Accepted->value)
            ->whereNotNull('provider_message_id')
            ->get();

        $campaignIds = [];

        foreach ($recipients as $recipient) {
            $result = $gateway->messageReport((string) $recipient->provider_message_id);

            $attributes = ['last_reconciled_at' => now()];

            if ($result->ok && $result->state !== null) {
                $state = $result->state;

                $attributes['provider_status'] = Str::limit((string) ($result->providerStatus ?? $state->value), 60, '');

                if ($state === DeliveryState::Delivered) {
                    $attributes['status'] = RecipientStatus::Delivered->value;
                    $attributes['delivered_at'] = now();
                    $attributes['error_code'] = null;
                    $attributes['error_message'] = null;
                } elseif ($state === DeliveryState::Failed) {
                    $attributes['status'] = RecipientStatus::Failed->value;
                    $attributes['failed_at'] = now();
                    $attributes['error_code'] = 'delivery_failed';
                    $attributes['error_message'] = Str::limit(
                        sprintf('Termii reported "%s" for this message.', $result->providerStatus ?? 'failed'),
                        190,
                        '',
                    );
                } elseif ($state === DeliveryState::Sent || $state === DeliveryState::Pending) {
                    $attributes['status'] = RecipientStatus::Accepted->value;
                }
            }

            $recipient->forceFill($attributes)->save();
            $campaignIds[$recipient->campaign_id] = true;
        }

        foreach (array_keys($campaignIds) as $campaignId) {
            $campaign = Campaign::query()->find($campaignId);

            if ($campaign !== null) {
                $progress->refresh($campaign);
            }
        }
    }
}
