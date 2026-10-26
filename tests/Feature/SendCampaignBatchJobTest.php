<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\ProviderErrorType;
use App\Enums\RecipientStatus;
use App\Exceptions\ProviderTemporaryException;
use App\Jobs\SendCampaignBatchJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Audit\AuditLogger;
use App\Services\Campaign\CampaignProgress;
use App\Services\Provider\ProviderHealthService;
use App\Services\Termii\TermiiGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSms;
use Tests\TestCase;

class SendCampaignBatchJobTest extends TestCase
{
    use InteractsWithSms;
    use RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGateway();
        $this->configureSettings();

        $admin = $this->admin();

        $this->campaign = Campaign::factory()->create([
            'created_by' => $admin->id,
            'status' => CampaignStatus::Processing,
            'confirmed_at' => now(),
            'queued_at' => now(),
            'sender_id' => 'YAC',
            'encoding' => 'gsm-7',
            'total_recipients' => 3,
            'dispatch_mode' => 'bulk',
        ]);
    }

    public function test_a_bulk_batch_marks_recipients_as_accepted_not_delivered(): void
    {
        $recipients = $this->recipients(3);

        $this->runJob($recipients->pluck('id')->all());

        $this->assertCount(1, $this->gateway->bulkSends);
        $this->assertCount(3, $this->gateway->bulkSends[0]['phones']);

        foreach ($recipients as $recipient) {
            $recipient->refresh();
            $this->assertSame(RecipientStatus::Accepted, $recipient->status);
            $this->assertNotNull($recipient->submitted_at);
            $this->assertNull($recipient->delivered_at, 'HTTP acceptance must never be recorded as delivery.');
        }

        $this->assertSame(CampaignStatus::Completed, $this->campaign->fresh()->status);
    }

    public function test_running_the_same_batch_twice_never_sends_twice(): void
    {
        $recipients = $this->recipients(2);
        $ids = $recipients->pluck('id')->all();

        $this->runJob($ids);
        $this->runJob($ids);

        $this->assertCount(1, $this->gateway->bulkSends);
    }

    public function test_an_ambiguous_timeout_is_marked_unknown_and_never_resent(): void
    {
        $this->gateway->failNextBulk(ProviderErrorType::AmbiguousTimeout, 'The request timed out.');

        $recipients = $this->recipients(2);

        $this->runJob($recipients->pluck('id')->all());

        foreach ($recipients as $recipient) {
            $recipient->refresh();
            $this->assertSame(RecipientStatus::Unknown, $recipient->status);
            $this->assertTrue($recipient->ambiguous);
            $this->assertSame('ambiguous_timeout', $recipient->error_code);
        }

        $this->assertSame(CampaignStatus::CompletedWithErrors, $this->campaign->fresh()->status);
        $this->assertCount(1, $this->gateway->bulkSends);

        // A retry attempt finds nothing to claim, so it cannot resend.
        $this->runJob($recipients->pluck('id')->all());
        $this->assertCount(1, $this->gateway->bulkSends);
    }

    public function test_a_retryable_error_releases_the_batch_and_throws_for_a_later_retry(): void
    {
        $this->gateway->failNextBulk(ProviderErrorType::RateLimited, 'Too many requests.', 429);

        $recipients = $this->recipients(2);

        try {
            $this->runJob($recipients->pluck('id')->all());
            $this->fail('Expected a ProviderTemporaryException.');
        } catch (ProviderTemporaryException) {
            // expected
        }

        foreach ($recipients as $recipient) {
            $recipient->refresh();
            $this->assertSame(RecipientStatus::Pending, $recipient->status);
            $this->assertSame(1, $recipient->attempts);
            $this->assertNull($recipient->claim_token);
        }
    }

    public function test_a_fatal_provider_error_stops_the_campaign(): void
    {
        $this->gateway->failNextBulk(ProviderErrorType::InsufficientBalance, 'Insufficient balance.', 400);

        $recipients = $this->recipients(3);

        $this->runJob($recipients->pluck('id')->all());

        $this->assertSame(CampaignStatus::Failed, $this->campaign->fresh()->status);
        $this->assertSame(3, $this->campaign->recipients()->where('status', RecipientStatus::Failed->value)->count());
        $this->assertSame('insufficient_balance', $this->campaign->recipients()->first()->error_code);

        $provider = \App\Models\ProviderStatus::termii();
        $this->assertSame('insufficient_balance', $provider->last_error_type);
    }

    public function test_individual_dispatch_is_used_for_personalised_messages(): void
    {
        $this->campaign->forceFill(['dispatch_mode' => 'individual'])->save();

        $recipients = CampaignRecipient::factory()->count(2)->create([
            'campaign_id' => $this->campaign->id,
            'status' => RecipientStatus::Pending,
            'rendered_message' => 'Unique message',
        ]);

        CampaignRecipient::query()->where('campaign_id', $this->campaign->id)->update(['rendered_message' => 'Unique message']);

        $this->runJob($recipients->pluck('id')->all());

        $this->assertCount(2, $this->gateway->singleSends);
        $this->assertCount(0, $this->gateway->bulkSends, 'Individual mode must not fall back to a bulk request.');
    }

    public function test_unicode_campaigns_use_the_unicode_message_type(): void
    {
        $this->campaign->forceFill(['encoding' => 'ucs-2'])->save();

        $this->runJob($this->recipients(1)->pluck('id')->all());

        $this->assertSame('unicode', $this->gateway->bulkSends[0]['type']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, CampaignRecipient>
     */
    private function recipients(int $count)
    {
        return CampaignRecipient::factory()->count($count)->create([
            'campaign_id' => $this->campaign->id,
            'status' => RecipientStatus::Pending,
            'rendered_message' => 'Hello, this is a YAC test message.',
            'segments' => 1,
        ]);
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function runJob(array $ids): void
    {
        $job = new SendCampaignBatchJob($this->campaign->id, $ids, 0);

        $job->handle(
            $this->app->make(TermiiGatewayInterface::class),
            $this->app->make(CampaignProgress::class),
            $this->app->make(ProviderHealthService::class),
            $this->app->make(AuditLogger::class),
        );
    }
}
