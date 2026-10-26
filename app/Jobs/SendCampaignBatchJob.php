<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Enums\ProviderErrorType;
use App\Enums\RecipientStatus;
use App\Enums\SmsEncoding;
use App\Exceptions\ProviderTemporaryException;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Audit\AuditLogger;
use App\Services\Campaign\CampaignProgress;
use App\Services\Provider\ProviderHealthService;
use App\Services\Termii\Data\ProviderResult;
use App\Services\Termii\TermiiGatewayInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one batch of a campaign through Termii.
 *
 * Safety model:
 *  - recipients are claimed atomically (pending -> processing with a claim
 *    token), so two workers can never send the same recipient;
 *  - transport errors that happened before the request was written are
 *    released back to "pending" and retried by the queue with backoff;
 *  - timeouts, where Termii may already have accepted the message, are marked
 *    "unknown" and are never retried automatically;
 *  - fatal provider errors (bad key, empty balance, rejected sender) stop the
 *    whole campaign instead of burning through the remaining recipients.
 */
class SendCampaignBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum send attempts for a single recipient across all jobs. */
    public const MAX_ATTEMPTS_PER_RECIPIENT = 3;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [60, 180];

    public int $uniqueFor = 3600;

    private ?string $claimToken = null;

    /**
     * @param  array<int, int>  $recipientIds
     */
    public function __construct(
        public readonly int $campaignId,
        public readonly array $recipientIds,
        public readonly int $batchIndex = 0,
    ) {}

    public function uniqueId(): string
    {
        return sprintf('campaign-batch-%d-%d', $this->campaignId, $this->batchIndex);
    }

    public function handle(
        TermiiGatewayInterface $gateway,
        CampaignProgress $progress,
        ProviderHealthService $health,
        AuditLogger $audit,
    ): void {
        $campaign = Campaign::query()->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        if (in_array($campaign->status, [
            CampaignStatus::Failed,
            CampaignStatus::Cancelled,
            CampaignStatus::Scheduled,
            CampaignStatus::Draft,
        ], true)) {
            return;
        }

        $claimed = $this->claim();

        if ($claimed->isEmpty()) {
            return;
        }

        $campaign->refresh();

        if (in_array($campaign->status, [CampaignStatus::Failed, CampaignStatus::Cancelled], true)) {
            // The campaign was stopped between confirmation and this batch.
            CampaignRecipient::query()
                ->where('claim_token', $this->claimToken)
                ->where('status', RecipientStatus::Processing->value)
                ->update([
                    'status' => RecipientStatus::Skipped->value,
                    'error_code' => 'campaign_cancelled',
                    'error_message' => 'The campaign was stopped before this batch was sent.',
                    'claim_token' => null,
                    'claimed_at' => null,
                    'failed_at' => now(),
                    'updated_at' => now(),
                ]);

            $progress->refresh($campaign);

            return;
        }

        try {
            $fatal = $campaign->usesPersonalisation()
                ? $this->sendIndividually($campaign, $claimed, $gateway)
                : $this->sendBatched($campaign, $claimed, $gateway);

            if ($fatal !== null) {
                $this->failCampaign($campaign, $fatal, $health, $audit);
            }
        } finally {
            $progress->refresh($campaign->fresh() ?? $campaign);
        }
    }

    /**
     * Called by the queue when every attempt for this job has failed.
     */
    public function failed(?Throwable $e): void
    {
        if ($this->claimToken !== null) {
            // The worker died mid-batch. The provider outcome for whatever was
            // in flight is unknown, so flag it for reconciliation - never
            // resend automatically.
            CampaignRecipient::query()
                ->where('claim_token', $this->claimToken)
                ->where('status', RecipientStatus::Processing->value)
                ->update([
                    'status' => RecipientStatus::Unknown->value,
                    'ambiguous' => true,
                    'provider_status' => 'worker_interrupted',
                    'error_code' => 'job_interrupted',
                    'error_message' => 'The sending worker stopped before the provider outcome was recorded. Reconcile this message before resending.',
                    'claim_token' => null,
                    'claimed_at' => null,
                    'updated_at' => now(),
                ]);
        }

        Log::warning('SMS batch job failed', [
            'campaign_id' => $this->campaignId,
            'batch' => $this->batchIndex,
            'error' => $e?->getMessage(),
        ]);

        $campaign = Campaign::query()->find($this->campaignId);

        if ($campaign !== null) {
            app(CampaignProgress::class)->refresh($campaign);
        }
    }

    /**
     * Atomically claim the pending recipients of this batch.
     *
     * The conditional UPDATE is the concurrency guard: two workers running the
     * same batch can each only claim rows that are still "pending".
     *
     * @return Collection<int, CampaignRecipient>
     */
    private function claim(): Collection
    {
        $this->claimToken = (string) Str::uuid();

        CampaignRecipient::query()
            ->where('campaign_id', $this->campaignId)
            ->whereIn('id', $this->recipientIds)
            ->where('status', RecipientStatus::Pending->value)
            ->update([
                'status' => RecipientStatus::Processing->value,
                'claim_token' => $this->claimToken,
                'claimed_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        return CampaignRecipient::query()
            ->where('claim_token', $this->claimToken)
            ->where('status', RecipientStatus::Processing->value)
            ->get();
    }

    /**
     * @param  Collection<int, CampaignRecipient>  $claimed
     */
    private function sendBatched(Campaign $campaign, Collection $claimed, TermiiGatewayInterface $gateway): ?ProviderResult
    {
        $type = $campaign->encoding === SmsEncoding::Ucs2->value ? 'unicode' : 'plain';
        $needsRetry = false;

        foreach ($claimed->groupBy('rendered_message') as $message => $group) {
            /** @var Collection<int, CampaignRecipient> $group */
            foreach ($group->chunk(100) as $chunk) {
                $result = $gateway->sendBulk(
                    phones: $chunk->pluck('phone')->all(),
                    message: (string) $message,
                    senderId: $campaign->sender_id,
                    channel: $campaign->channel,
                    type: $type,
                    campaign: $campaign,
                );

                if ($result->accepted) {
                    $this->markAccepted($chunk, $result);

                    continue;
                }

                if ($result->isFatal()) {
                    return $result;
                }

                if ($result->isAmbiguous()) {
                    $this->markUnknown($chunk, $result);

                    continue;
                }

                if ($this->canRetry($chunk, $result)) {
                    $this->revertToPending($chunk);
                    $needsRetry = true;

                    continue;
                }

                $this->markFailed($chunk, $result);
            }
        }

        if ($needsRetry) {
            throw new ProviderTemporaryException('A temporary Termii error occurred; the batch will be retried.');
        }

        return null;
    }

    /**
     * @param  Collection<int, CampaignRecipient>  $claimed
     */
    private function sendIndividually(Campaign $campaign, Collection $claimed, TermiiGatewayInterface $gateway): ?ProviderResult
    {
        $type = $campaign->encoding === SmsEncoding::Ucs2->value ? 'unicode' : 'plain';
        $needsRetry = false;

        foreach ($claimed as $recipient) {
            $result = $gateway->sendSingle(
                phone: $recipient->phone,
                message: $recipient->rendered_message,
                senderId: $campaign->sender_id,
                channel: $campaign->channel,
                type: $type,
                campaign: $campaign,
                recipient: $recipient,
            );

            if ($result->accepted) {
                $this->markAccepted(collect([$recipient]), $result);

                continue;
            }

            if ($result->isFatal()) {
                return $result;
            }

            $single = collect([$recipient]);

            if ($result->isAmbiguous()) {
                $this->markUnknown($single, $result);

                continue;
            }

            if ($this->canRetry($single, $result)) {
                $this->revertToPending($single);
                $needsRetry = true;

                continue;
            }

            $this->markFailed($single, $result);
        }

        if ($needsRetry) {
            throw new ProviderTemporaryException('A temporary Termii error occurred; the batch will be retried.');
        }

        return null;
    }

    /**
     * @param  Collection<int, CampaignRecipient>  $claims
     */
    private function canRetry(Collection $claims, ProviderResult $result): bool
    {
        if (! $result->isRetryable()) {
            return false;
        }

        if ($this->attempts() >= $this->tries) {
            return false;
        }

        return $claims->every(
            fn (CampaignRecipient $recipient) => (int) $recipient->attempts < self::MAX_ATTEMPTS_PER_RECIPIENT
        );
    }

    /**
     * @param  Collection<int, CampaignRecipient>  $claims
     */
    private function markAccepted(Collection $claims, ProviderResult $result): void
    {
        // A bulk request returns one message id for the whole batch, so the id
        // can only be stored when the batch contains a single recipient.
        $messageId = $claims->count() === 1 ? $result->messageId : null;

        foreach ($claims as $recipient) {
            $recipient->forceFill([
                'status' => RecipientStatus::Accepted,
                'provider' => 'termii',
                'provider_message_id' => $messageId,
                'provider_status' => 'accepted',
                'submitted_at' => now(),
                'claim_token' => null,
                'claimed_at' => null,
                'error_code' => null,
                'error_message' => null,
                'ambiguous' => false,
            ])->save();
        }
    }

    /**
     * @param  Collection<int, CampaignRecipient>  $claims
     */
    private function markUnknown(Collection $claims, ProviderResult $result): void
    {
        $type = $result->errorType ?? ProviderErrorType::Unknown;

        foreach ($claims as $recipient) {
            $recipient->forceFill([
                'status' => RecipientStatus::Unknown,
                'provider_status' => 'ambiguous',
                'error_code' => $type->value,
                'error_message' => Str::limit($result->safeMessage(), 190, ''),
                'ambiguous' => true,
                'claim_token' => null,
                'claimed_at' => null,
            ])->save();
        }
    }

    /**
     * @param  Collection<int, CampaignRecipient>  $claims
     */
    private function markFailed(Collection $claims, ProviderResult $result): void
    {
        $type = $result->errorType ?? ProviderErrorType::Unknown;

        foreach ($claims as $recipient) {
            $recipient->forceFill([
                'status' => RecipientStatus::Failed,
                'provider_status' => 'failed',
                'error_code' => $type->value,
                'error_message' => Str::limit($result->safeMessage(), 190, ''),
                'ambiguous' => false,
                'claim_token' => null,
                'claimed_at' => null,
                'failed_at' => now(),
            ])->save();
        }
    }

    /**
     * @param  Collection<int, CampaignRecipient>  $claims
     */
    private function revertToPending(Collection $claims): void
    {
        CampaignRecipient::query()
            ->whereIn('id', $claims->pluck('id')->all())
            ->where('status', RecipientStatus::Processing->value)
            ->update([
                'status' => RecipientStatus::Pending->value,
                'claim_token' => null,
                'claimed_at' => null,
                'error_code' => null,
                'error_message' => null,
                'ambiguous' => false,
                'updated_at' => now(),
            ]);
    }

    private function failCampaign(
        Campaign $campaign,
        ProviderResult $result,
        ProviderHealthService $health,
        AuditLogger $audit,
    ): void {
        $type = $result->errorType ?? ProviderErrorType::Unknown;

        DB::transaction(function () use ($campaign, $result, $type): void {
            $campaign->recipients()
                ->whereIn('status', [RecipientStatus::Pending->value, RecipientStatus::Processing->value])
                ->update([
                    'status' => RecipientStatus::Failed->value,
                    'error_code' => $type->value,
                    'error_message' => Str::limit($result->safeMessage(), 190, ''),
                    'claim_token' => null,
                    'claimed_at' => null,
                    'failed_at' => now(),
                    'updated_at' => now(),
                ]);

            $campaign->forceFill([
                'status' => CampaignStatus::Failed,
                'completed_at' => now(),
                'failure_reason' => Str::limit($result->safeMessage(), 900, ''),
            ])->save();
        });

        $health->recordSendFailure($type, $result->safeMessage());

        $audit->log('campaign.failed', $campaign, sprintf(
            'Campaign "%s" stopped: %s',
            $campaign->name,
            $result->safeMessage(),
        ), [
            'error_type' => $type->value,
        ]);
    }
}
