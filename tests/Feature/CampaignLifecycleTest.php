<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Exceptions\CampaignStateException;
use App\Jobs\DispatchCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\SuppressionEntry;
use App\Services\Campaign\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSms;
use Tests\TestCase;

class CampaignLifecycleTest extends TestCase
{
    use InteractsWithSms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGateway();
        $this->configureSettings();
    }

    public function test_confirming_a_campaign_materialises_a_deduplicated_audience(): void
    {
        Queue::fake();

        $admin = $this->admin();

        $direct = Contact::factory()->create(['phone' => '2348031234567']);

        $groupA = ContactGroup::factory()->create();
        $groupB = ContactGroup::factory()->create();
        $inGroups = Contact::factory()->create(['phone' => '2348098765432']);
        $inGroups->groups()->attach([$groupA->id, $groupB->id]);

        $unsubscribed = Contact::factory()->unsubscribed()->create(['phone' => '2348055550000']);

        SuppressionEntry::factory()->create([
            'phone' => '2348077770000',
            'reason' => 'Customer asked to stop',
            'source' => 'manual',
        ]);
        $suppressed = Contact::factory()->create(['phone' => '2348077770000']);

        $campaign = $this->confirmedCampaign($admin, [
            'audience' => [
                'contact_ids' => [$direct->id, $unsubscribed->id, $suppressed->id, $inGroups->id],
                'group_ids' => [$groupA->id, $groupB->id],
            ],
        ]);

        $confirmed = app(CampaignService::class)->confirm($campaign, $admin);

        $this->assertSame(CampaignStatus::Queued, $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertSame(2, $confirmed->total_recipients);
        $this->assertSame(2, $confirmed->excluded_count);

        $phones = $confirmed->recipients()->orderBy('id')->pluck('phone')->all();

        $this->assertEqualsCanonicalizing(['2348031234567', '2348098765432'], $phones);
        $this->assertSame(2, $confirmed->estimated_total_segments);

        Queue::assertPushed(DispatchCampaignJob::class, fn ($job) => $job->campaignId === $confirmed->id);
    }

    public function test_confirming_twice_is_rejected_and_does_not_duplicate_recipients(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $contact = Contact::factory()->create();
        $campaign = $this->confirmedCampaign($admin, ['audience' => ['contact_ids' => [$contact->id], 'group_ids' => []]]);

        $service = app(CampaignService::class);
        $service->confirm($campaign, $admin);

        $this->expectException(CampaignStateException::class);

        try {
            $service->confirm($campaign->fresh(), $admin);
        } finally {
            $this->assertSame(1, CampaignRecipient::query()->where('campaign_id', $campaign->id)->count());
        }
    }

    public function test_a_scheduled_campaign_only_materialises_when_it_becomes_due(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $contact = Contact::factory()->create();
        $campaign = $this->confirmedCampaign($admin, [
            'audience' => ['contact_ids' => [$contact->id], 'group_ids' => []],
            'scheduled_at' => now()->addHours(2),
        ]);

        $service = app(CampaignService::class);
        $scheduled = $service->confirm($campaign, $admin);

        $this->assertSame(CampaignStatus::Scheduled, $scheduled->status);
        $this->assertSame(0, CampaignRecipient::query()->where('campaign_id', $campaign->id)->count());
        Queue::assertNotPushed(DispatchCampaignJob::class);

        $campaign->forceFill(['scheduled_at' => now()->subMinute()])->save();

        $this->assertTrue($service->dispatchScheduled($campaign->fresh()));

        $this->assertSame(CampaignStatus::Queued, $campaign->fresh()->status);
        $this->assertSame(1, CampaignRecipient::query()->where('campaign_id', $campaign->id)->count());
        Queue::assertPushed(DispatchCampaignJob::class);
    }

    public function test_dispatch_scheduled_is_idempotent(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $contact = Contact::factory()->create();
        $campaign = $this->confirmedCampaign($admin, [
            'audience' => ['contact_ids' => [$contact->id], 'group_ids' => []],
            'scheduled_at' => now()->addHour(),
        ]);

        $service = app(CampaignService::class);
        $service->confirm($campaign, $admin);
        $campaign->forceFill(['scheduled_at' => now()->subMinute()])->save();

        $this->assertTrue($service->dispatchScheduled($campaign->fresh()));
        $this->assertFalse($service->dispatchScheduled($campaign->fresh()));
        $this->assertSame(1, CampaignRecipient::query()->where('campaign_id', $campaign->id)->count());
    }

    public function test_cancelling_skips_all_unsent_recipients(): void
    {
        $admin = $this->admin();
        $campaign = Campaign::factory()->create([
            'created_by' => $admin->id,
            'status' => CampaignStatus::Queued,
            'confirmed_at' => now(),
        ]);

        CampaignRecipient::factory()->count(3)->create([
            'campaign_id' => $campaign->id,
            'status' => RecipientStatus::Pending,
        ]);

        app(CampaignService::class)->cancel($campaign, $admin, 'Testing');

        $campaign->refresh();

        $this->assertSame(CampaignStatus::Cancelled, $campaign->status);
        $this->assertSame(3, $campaign->recipients()->where('status', RecipientStatus::Skipped->value)->count());
        $this->assertSame('Testing', $campaign->failure_reason);
    }

    public function test_retry_failed_only_requeues_safely_retryable_failures(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $campaign = Campaign::factory()->create([
            'created_by' => $admin->id,
            'status' => CampaignStatus::CompletedWithErrors,
            'confirmed_at' => now(),
            'completed_at' => now(),
        ]);

        CampaignRecipient::factory()->failed('server_error')->create(['campaign_id' => $campaign->id]);
        CampaignRecipient::factory()->failed('rate_limited')->create(['campaign_id' => $campaign->id]);
        $ambiguous = CampaignRecipient::factory()->failed('ambiguous_timeout')->create(['campaign_id' => $campaign->id]);

        $count = app(CampaignService::class)->retryFailed($campaign, $admin);

        $this->assertSame(2, $count);
        $this->assertSame(2, $campaign->recipients()->where('status', RecipientStatus::Pending->value)->count());

        $ambiguous->refresh();
        $this->assertSame(RecipientStatus::Failed, $ambiguous->status);
        $this->assertSame('ambiguous_timeout', $ambiguous->error_code);
    }

    public function test_a_campaign_with_no_eligible_recipients_cannot_be_confirmed(): void
    {
        $admin = $this->admin();
        $contact = Contact::factory()->unsubscribed()->create();

        $campaign = $this->confirmedCampaign($admin, ['audience' => ['contact_ids' => [$contact->id], 'group_ids' => []]]);

        $this->expectException(\App\Exceptions\CampaignPreflightException::class);

        app(CampaignService::class)->confirm($campaign, $admin);
    }

    public function test_sending_paused_blocks_confirmation(): void
    {
        $this->configureSettings(['sending.paused' => true]);

        $admin = $this->admin();
        $contact = Contact::factory()->create();
        $campaign = $this->confirmedCampaign($admin, ['audience' => ['contact_ids' => [$contact->id], 'group_ids' => []]]);

        $this->expectException(\App\Exceptions\CampaignPreflightException::class);

        app(CampaignService::class)->confirm($campaign, $admin);
    }
}
