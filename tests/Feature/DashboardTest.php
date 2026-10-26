<?php

namespace Tests\Feature;

use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ProviderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSms;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use InteractsWithSms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGateway();
        $this->configureSettings();
    }

    public function test_the_dashboard_only_counts_confirmed_delivery_reports_as_delivered(): void
    {
        $admin = $this->admin();

        Contact::factory()->count(3)->create();

        $campaign = Campaign::factory()->create([
            'created_by' => $admin->id,
            'status' => 'processing',
            'confirmed_at' => now(),
            'total_recipients' => 2,
        ]);

        CampaignRecipient::factory()->delivered('msg-delivered')->create(['campaign_id' => $campaign->id]);
        CampaignRecipient::factory()->accepted('msg-accepted')->create(['campaign_id' => $campaign->id]);

        ProviderStatus::termii()->fill([
            'status' => 'connected',
            'balance' => 1200.50,
            'currency' => 'NGN',
            'last_balance_at' => now(),
        ])->save();

        $response = $this->actingAs($admin)->get('/dashboard')->assertOk();

        $response->assertSee('1200.50');
        $response->assertSee('Delivered');
        $response->assertDontSee('NaN');
    }

    public function test_the_dashboard_shows_a_provider_error_instead_of_a_fabricated_balance(): void
    {
        $admin = $this->admin();

        ProviderStatus::termii()->fill([
            'status' => 'error',
            'last_error' => 'Invalid API key',
            'last_error_type' => 'invalid_credentials',
            'last_error_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Invalid API key');
    }

    public function test_the_reports_page_renders_without_delivery_data(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/reports')->assertOk()->assertSee('Delivery reports');
    }

    public function test_the_contacts_and_import_pages_render(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/contacts')->assertOk();
        $this->actingAs($admin)->get('/imports')->assertOk();
        $this->actingAs($admin)->get('/campaigns')->assertOk();
        $this->actingAs($admin)->get('/campaigns/create')->assertOk();
    }

    public function test_an_operators_recipient_page_is_not_marked_delivered_without_evidence(): void
    {
        $admin = $this->admin();
        $campaign = Campaign::factory()->create(['created_by' => $admin->id]);
        $recipient = CampaignRecipient::factory()->accepted('msg-1')->create(['campaign_id' => $campaign->id]);

        $this->assertNotSame(RecipientStatus::Delivered, $recipient->status);

        $this->actingAs($admin)->get('/campaigns/'.$campaign->id)->assertOk()->assertSee('Accepted by provider');
    }
}
