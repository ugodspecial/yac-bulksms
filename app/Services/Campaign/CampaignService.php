<?php

namespace App\Services\Campaign;

use App\Enums\CampaignStatus;
use App\Enums\DispatchMode;
use App\Enums\ProviderErrorType;
use App\Enums\RecipientStatus;
use App\Enums\SmsEncoding;
use App\Exceptions\CampaignPreflightException;
use App\Exceptions\CampaignStateException;
use App\Jobs\DispatchCampaignJob;
use App\Jobs\SendCampaignBatchJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ProviderStatus;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Sms\MessageRenderer;
use App\Services\Sms\SegmentCalculator;
use App\Services\Settings\SettingsService;
use App\Services\Termii\TermiiGatewayInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owns the campaign lifecycle: drafts, review, explicit confirmation,
 * dispatch, cancellation and controlled retries.
 */
class CampaignService
{
    public function __construct(
        private readonly RecipientResolver $resolver,
        private readonly SegmentCalculator $segments,
        private readonly MessageRenderer $renderer,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly TermiiGatewayInterface $gateway,
    ) {}

    /**
     * @param  array{name: string, message: string, sender_id: string, channel?: string, audience?: array{contact_ids?: array<int, int|string>, group_ids?: array<int, int|string>}, scheduled_at?: CarbonInterface|string|null}  $data
     */
    public function create(User $actor, array $data): Campaign
    {
        $payload = $this->validatedPayload($data);
        $preview = $this->renderPreview($payload['message']);

        $campaign = Campaign::create([
            'name' => $payload['name'],
            'message' => $payload['message'],
            'sender_id' => $payload['sender_id'],
            'channel' => $payload['channel'],
            'dispatch_mode' => $preview['uses_personalisation'] ? DispatchMode::Individual : DispatchMode::Bulk,
            'status' => CampaignStatus::Draft,
            'encoding' => $preview['encoding'],
            'max_segments' => $preview['segments'],
            'audience' => $payload['audience'],
            'scheduled_at' => $payload['scheduled_at'],
            'created_by' => $actor->id,
            'batch_size' => $this->settings->batchSize(),
        ]);

        $this->audit->log('campaign.created', $campaign, sprintf('Created campaign "%s".', $campaign->name), [
            'sender_id' => $campaign->sender_id,
            'channel' => $campaign->channel,
        ], $actor);

        return $campaign;
    }

    /**
     * @param  array{name: string, message: string, sender_id: string, channel?: string, audience?: array{contact_ids?: array<int, int|string>, group_ids?: array<int, int|string>}, scheduled_at?: CarbonInterface|string|null}  $data
     */
    public function update(Campaign $campaign, User $actor, array $data): Campaign
    {
        $campaign->refresh();

        if (! $campaign->status->isEditable()) {
            throw new CampaignStateException('Only draft campaigns can be edited.');
        }

        if ($campaign->recipients()->exists()) {
            throw new CampaignStateException('This campaign has already been prepared for sending and can no longer be edited.');
        }

        $payload = $this->validatedPayload($data);
        $preview = $this->renderPreview($payload['message']);

        $campaign->forceFill([
            'name' => $payload['name'],
            'message' => $payload['message'],
            'sender_id' => $payload['sender_id'],
            'channel' => $payload['channel'],
            'dispatch_mode' => $preview['uses_personalisation'] ? DispatchMode::Individual : DispatchMode::Bulk,
            'encoding' => $preview['encoding'],
            'max_segments' => $preview['segments'],
            'audience' => $payload['audience'],
            'scheduled_at' => $payload['scheduled_at'],
        ])->save();

        $this->audit->log('campaign.updated', $campaign, sprintf('Updated campaign "%s".', $campaign->name), [], $actor);

        return $campaign;
    }

    public function delete(Campaign $campaign, User $actor): void
    {
        $campaign->refresh();

        if (! $campaign->status->isEditable() || $campaign->recipients()->exists()) {
            throw new CampaignStateException('Only drafts that have never been sent can be deleted.');
        }

        $name = $campaign->name;
        $campaign->delete();

        $this->audit->log('campaign.deleted', null, sprintf('Deleted campaign "%s".', $name), [], $actor);
    }

    /**
     * The plan an operator sees on the review screen.
     */
    public function plan(Campaign $campaign): RecipientPlan
    {
        return $this->resolver->resolveForAudience($campaign->audience);
    }

    /**
     * Segment estimate for a single recipient: used by the review screen.
     */
    public function estimate(Campaign $campaign, RecipientPlan $plan): array
    {
        $totalSegments = 0;
        $maxSegments = 0;
        $encoding = SmsEncoding::Gsm7;

        foreach ($plan->recipients as $recipient) {
            $rendered = $this->renderer->render($campaign->message, $recipient);
            $segments = $this->segments->calculate($rendered);

            $totalSegments += $segments->segments;
            $maxSegments = max($maxSegments, $segments->segments);

            if ($segments->encoding === SmsEncoding::Ucs2) {
                $encoding = SmsEncoding::Ucs2;
            }
        }

        return [
            'recipients' => $plan->count(),
            'total_segments' => $totalSegments,
            'max_segments' => $maxSegments,
            'encoding' => $encoding->value,
            'encoding_label' => $encoding->label(),
            'excluded' => $plan->exclusionSummary(),
            'excluded_count' => $plan->excludedCount(),
        ];
    }

    /**
     * Confirm a draft and either queue it immediately or schedule it.
     *
     * A row lock plus the status transition guarantee that concurrent or
     * repeated confirmations (double clicks, retries, two browser tabs)
     * cannot create recipients or dispatch twice.
     */
    public function confirm(Campaign $campaign, User $actor): Campaign
    {
        $confirmed = DB::transaction(function () use ($campaign, $actor): Campaign {
            /** @var Campaign $locked */
            $locked = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->confirmed_at !== null) {
                throw new CampaignStateException('This campaign has already been confirmed.');
            }

            if (! $locked->status->canBeConfirmed()) {
                throw new CampaignStateException(sprintf('Campaigns with status "%s" cannot be confirmed.', $locked->status->label()));
            }

            $this->assertSendable($locked);

            $plan = $this->resolver->resolveForAudience($locked->audience);

            if ($plan->count() === 0) {
                throw new CampaignPreflightException(
                    'No eligible recipients remain. Unsubscribed, suppressed and duplicate numbers are excluded - review the audience or the suppression list.'
                );
            }

            $estimate = $this->estimate($locked, $plan);

            if ($estimate['max_segments'] > $this->settings->maxSegments()) {
                throw new CampaignPreflightException(sprintf(
                    'The longest personalised message needs %d SMS segments, above the configured limit of %d.',
                    $estimate['max_segments'],
                    $this->settings->maxSegments(),
                ));
            }

            $scheduledFor = $locked->scheduled_at;

            if ($scheduledFor !== null && $scheduledFor->isFuture()) {
                $locked->forceFill([
                    'status' => CampaignStatus::Scheduled,
                    'confirmed_at' => now(),
                    'confirmed_by' => $actor->id,
                    'total_recipients' => $plan->count(),
                    'excluded_count' => $plan->excludedCount(),
                    'exclusion_summary' => $plan->exclusionsForStorage(),
                    'estimated_total_segments' => $estimate['total_segments'],
                    'encoding' => $estimate['encoding'],
                    'balance_before' => ProviderStatus::termii()->balance,
                ])->save();

                return $locked;
            }

            if ($scheduledFor !== null) {
                $locked->scheduled_at = null;
            }

            $this->materialiseRecipients($locked, $plan, $estimate);

            $locked->forceFill([
                'status' => CampaignStatus::Queued,
                'confirmed_at' => now(),
                'confirmed_by' => $actor->id,
                'queued_at' => now(),
                'total_recipients' => $plan->count(),
                'excluded_count' => $plan->excludedCount(),
                'exclusion_summary' => $plan->exclusionsForStorage(),
                'estimated_total_segments' => $estimate['total_segments'],
                'encoding' => $estimate['encoding'],
                'balance_before' => ProviderStatus::termii()->balance,
                'failure_reason' => null,
            ])->save();

            return $locked;
        });

        $this->audit->log('campaign.confirmed', $confirmed, sprintf(
            'Confirmed campaign "%s" for %d recipients (%d estimated SMS segments).',
            $confirmed->name,
            $confirmed->total_recipients,
            $confirmed->estimated_total_segments,
        ), [
            'status' => $confirmed->status->value,
            'scheduled_at' => $confirmed->scheduled_at?->toIso8601String(),
        ], $actor);

        if ($confirmed->status === CampaignStatus::Queued) {
            DispatchCampaignJob::dispatch($confirmed->id);
        }

        return $confirmed;
    }

    /**
     * Move a scheduled campaign into the queue when it becomes due. The
     * conditional status update makes this safe to run from multiple servers.
     */
    public function dispatchScheduled(Campaign $campaign): bool
    {
        $claimed = Campaign::query()
            ->whereKey($campaign->getKey())
            ->where('status', CampaignStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->update([
                'status' => CampaignStatus::Queued->value,
                'queued_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $campaign->refresh();

        try {
            DB::transaction(function () use ($campaign): void {
                $this->assertSendable($campaign);

                $plan = $this->resolver->resolveForAudience($campaign->audience);

                if ($plan->count() === 0) {
                    throw new CampaignPreflightException('No eligible recipients remain for this scheduled campaign.');
                }

                $estimate = $this->estimate($campaign, $plan);

                $this->materialiseRecipients($campaign, $plan, $estimate);

                $campaign->forceFill([
                    'total_recipients' => $plan->count(),
                    'excluded_count' => $plan->excludedCount(),
                    'exclusion_summary' => $plan->exclusionsForStorage(),
                    'estimated_total_segments' => $estimate['total_segments'],
                    'encoding' => $estimate['encoding'],
                ])->save();
            });
        } catch (\Throwable $e) {
            $campaign->forceFill([
                'status' => CampaignStatus::NeedsReview,
                'failure_reason' => Str::limit($e->getMessage(), 900),
            ])->save();

            $this->audit->log('campaign.schedule_failed', $campaign, sprintf(
                'Scheduled campaign "%s" could not be dispatched: %s',
                $campaign->name,
                $e->getMessage(),
            ));

            return false;
        }

        $this->audit->log('campaign.scheduled_dispatched', $campaign, sprintf(
            'Scheduled campaign "%s" was queued for %d recipients.',
            $campaign->name,
            $campaign->total_recipients,
        ));

        DispatchCampaignJob::dispatch($campaign->id);

        return true;
    }

    public function cancel(Campaign $campaign, ?User $actor, string $reason = ''): void
    {
        DB::transaction(function () use ($campaign, $actor, $reason): void {
            /** @var Campaign $locked */
            $locked = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canBeCancelled()) {
                throw new CampaignStateException(sprintf('Campaigns with status "%s" cannot be cancelled.', $locked->status->label()));
            }

            $locked->recipients()
                ->whereIn('status', [RecipientStatus::Pending->value, RecipientStatus::Processing->value])
                ->update([
                    'status' => RecipientStatus::Skipped->value,
                    'error_code' => 'campaign_cancelled',
                    'error_message' => 'The campaign was cancelled before this message was sent.',
                    'claim_token' => null,
                    'claimed_at' => null,
                    'updated_at' => now(),
                ]);

            $locked->forceFill([
                'status' => CampaignStatus::Cancelled,
                'cancelled_at' => now(),
                'completed_at' => $locked->completed_at ?? now(),
                'failure_reason' => $reason !== '' ? Str::limit($reason, 900) : 'Cancelled by '.($actor?->name ?? 'the application').'.',
            ])->save();
        });

        $this->audit->log('campaign.cancelled', $campaign, sprintf('Cancelled campaign "%s".', $campaign->name), [
            'reason' => $reason,
        ], $actor);
    }

    /**
     * Re-queue failed recipients whose failure is known to be safe to retry.
     * Ambiguous (unknown) outcomes are never retried automatically.
     */
    public function retryFailed(Campaign $campaign, User $actor): int
    {
        $campaign->refresh();

        if (! $this->gateway->isConfigured()) {
            throw new CampaignPreflightException('Termii is not configured, so failed messages cannot be retried.');
        }

        if ($this->settings->sendingPaused()) {
            throw new CampaignPreflightException('Sending is paused in Settings. Resume sending before retrying.');
        }

        $inFlight = $campaign->recipients()
            ->whereIn('status', [RecipientStatus::Pending->value, RecipientStatus::Processing->value])
            ->exists();

        if ($inFlight) {
            throw new CampaignStateException('This campaign still has messages waiting to be sent. Retry once it has finished.');
        }

        $retryable = [
            ProviderErrorType::RateLimited->value,
            ProviderErrorType::ServerError->value,
            ProviderErrorType::NetworkError->value,
        ];

        $ids = $campaign->recipients()
            ->where('status', RecipientStatus::Failed->value)
            ->whereIn('error_code', $retryable)
            ->where('attempts', '<', SendCampaignBatchJob::MAX_ATTEMPTS_PER_RECIPIENT)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            throw new CampaignStateException(
                'No failed messages are eligible for retry. Only failures that were safe to retry (rate limit, provider outage or network refusal) can be resent.'
            );
        }

        DB::transaction(function () use ($campaign, $ids): void {
            foreach (array_chunk($ids, 500) as $chunk) {
                CampaignRecipient::query()
                    ->whereIn('id', $chunk)
                    ->update([
                        'status' => RecipientStatus::Pending->value,
                        'error_code' => null,
                        'error_message' => null,
                        'ambiguous' => false,
                        'claim_token' => null,
                        'claimed_at' => null,
                        'failed_at' => null,
                        'updated_at' => now(),
                    ]);
            }

            $campaign->forceFill([
                'status' => CampaignStatus::Queued,
                'queued_at' => now(),
                'completed_at' => null,
                'cancelled_at' => null,
                'failure_reason' => null,
            ])->save();
        });

        $this->queueRecipientIds($campaign, $ids, offset: (int) now()->format('His'));

        $this->audit->log('campaign.retried', $campaign, sprintf(
            'Re-queued %d failed messages for campaign "%s".',
            count($ids),
            $campaign->name,
        ), ['recipients' => count($ids)], $actor);

        return count($ids);
    }

    /**
     * @param  array<int, int>  $ids
     */
    public function queueRecipientIds(Campaign $campaign, array $ids, int $offset = 0): void
    {
        $size = max(1, min(100, (int) ($campaign->batch_size ?: $this->settings->batchSize())));

        foreach (array_chunk($ids, $size) as $index => $chunk) {
            SendCampaignBatchJob::dispatch($campaign->id, $chunk, $offset + $index);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, message: string, sender_id: string, channel: string, audience: array{contact_ids: array<int, int>, group_ids: array<int, int>}, scheduled_at: Carbon|null}
     */
    private function validatedPayload(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $message = (string) ($data['message'] ?? '');
        $senderId = trim((string) ($data['sender_id'] ?? ''));
        $channel = (string) ($data['channel'] ?? $this->settings->defaultChannel());

        $errors = [];

        if ($name === '') {
            $errors['name'] = 'A campaign name is required.';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'The campaign name may not be longer than 120 characters.';
        }

        if (trim($message) === '') {
            $errors['message'] = 'The message cannot be empty.';
        } elseif (mb_strlen($message) > $this->settings->maxMessageLength()) {
            $errors['message'] = sprintf('The message may not be longer than %d characters.', $this->settings->maxMessageLength());
        } else {
            $templateErrors = $this->renderer->validate($message, $this->settings->customFields());

            if ($templateErrors !== []) {
                $errors['message'] = $templateErrors[0];
            }
        }

        $approved = $this->settings->approvedSenderIds();

        if ($senderId === '') {
            $errors['sender_id'] = 'Select a sender ID.';
        } elseif (! in_array($senderId, $approved, true)) {
            $errors['sender_id'] = 'The selected sender ID is not approved in Settings.';
        }

        if (! in_array($channel, ['generic', 'dnd'], true)) {
            $errors['channel'] = 'Select a supported messaging channel.';
        }

        $scheduledAt = null;
        $rawSchedule = $data['scheduled_at'] ?? null;

        if ($rawSchedule !== null && $rawSchedule !== '') {
            try {
                $scheduledAt = $rawSchedule instanceof CarbonInterface ? Carbon::instance($rawSchedule) : Carbon::parse((string) $rawSchedule);
            } catch (\Throwable) {
                $errors['scheduled_at'] = 'The scheduled date is not a valid date.';
            }

            if ($scheduledAt !== null && $scheduledAt->isPast()) {
                $errors['scheduled_at'] = 'The scheduled date must be in the future.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $audience = $data['audience'] ?? [];

        return [
            'name' => $name,
            'message' => $message,
            'sender_id' => $senderId,
            'channel' => $channel,
            'audience' => [
                'contact_ids' => array_values(array_unique(array_map('intval', $audience['contact_ids'] ?? []))),
                'group_ids' => array_values(array_unique(array_map('intval', $audience['group_ids'] ?? []))),
            ],
            'scheduled_at' => $scheduledAt,
        ];
    }

    /**
     * @return array{segments: int, encoding: SmsEncoding, uses_personalisation: bool}
     */
    private function renderPreview(string $message): array
    {
        $segments = $this->segments->calculate($this->renderer->sample($message, $this->settings->customFields()));

        return [
            'segments' => $segments->segments,
            'encoding' => $segments->encoding->value,
            'uses_personalisation' => $this->renderer->containsPlaceholders($message),
        ];
    }

    private function assertSendable(Campaign $campaign): void
    {
        if (! $this->gateway->isConfigured()) {
            throw new CampaignPreflightException('Termii is not configured. Add the API key to the server environment before sending campaigns.');
        }

        if ($this->settings->sendingPaused()) {
            throw new CampaignPreflightException('Sending is paused in Settings. Resume sending before confirming campaigns.');
        }

        if (! in_array($campaign->sender_id, $this->settings->approvedSenderIds(), true)) {
            throw new CampaignPreflightException('The sender ID on this campaign is not in the approved sender ID list.');
        }

        $providerStatus = ProviderStatus::termii();

        if ($providerStatus->balance !== null && (float) $providerStatus->balance <= 0.0) {
            throw new CampaignPreflightException(
                'The shared Termii balance is zero. Top up the Termii account (or re-check the balance in Settings) before sending.'
            );
        }

        $requiredSegments = $this->segments->calculate($this->renderer->sample($campaign->message, $this->settings->customFields()))->segments;

        if ($requiredSegments > $this->settings->maxSegments()) {
            throw new CampaignPreflightException(sprintf(
                'A single recipient message needs %d SMS segments, above the configured limit of %d.',
                $requiredSegments,
                $this->settings->maxSegments(),
            ));
        }
    }

    private function materialiseRecipients(Campaign $campaign, RecipientPlan $plan, array $estimate): void
    {
        $rows = [];
        $now = now();

        foreach ($plan->recipients as $recipient) {
            $rendered = $this->renderer->render($campaign->message, $recipient);
            $segments = $this->segments->calculate($rendered);

            if ($segments->segments > $this->settings->maxSegments()) {
                throw new CampaignPreflightException(sprintf(
                    'The personalised message for %s needs %d SMS segments, above the configured limit of %d. Shorten the message or reduce the personalised values.',
                    $recipient['phone'],
                    $segments->segments,
                    $this->settings->maxSegments(),
                ));
            }

            $rows[] = [
                'campaign_id' => $campaign->id,
                'contact_id' => $recipient['contact_id'],
                'phone' => $recipient['phone'],
                'name' => $recipient['name'],
                'rendered_message' => $rendered,
                'segments' => $segments->segments,
                'status' => RecipientStatus::Pending->value,
                'provider' => 'termii',
                'attempts' => 0,
                'ambiguous' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            CampaignRecipient::insertOrIgnore($chunk);
        }

        if ($campaign->recipients()->count() !== $plan->count()) {
            throw new CampaignPreflightException('The recipient list could not be prepared consistently. Please try again.');
        }
    }
}
