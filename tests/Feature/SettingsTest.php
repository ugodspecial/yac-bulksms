<?php

namespace Tests\Feature;

use App\Livewire\Settings\Index as SettingsIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithSms;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use InteractsWithSms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGateway();
        $this->configureSettings();
    }

    public function test_the_api_key_is_never_rendered_on_the_settings_screen(): void
    {
        $this->actingAs($this->admin())
            ->get('/settings')
            ->assertOk()
            ->assertDontSee('test-api-key-value')
            ->assertSee('••••••••alue');
    }

    public function test_an_administrator_can_save_the_application_settings(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(SettingsIndex::class)
            ->assertSet('appName', 'YAC-BulkSMS')
            ->set('appName', 'YAC Messaging')
            ->set('approvedSenderIds', 'YAC, YAC Alerts')
            ->set('defaultSenderId', 'YAC')
            ->set('batchSize', '50')
            ->set('maxSegments', '4')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('YAC Messaging', settings()->appName());
        $this->assertSame(50, settings()->batchSize());
        $this->assertSame(4, settings()->maxSegments());
        $this->assertSame(['YAC', 'YAC Alerts'], settings()->approvedSenderIds());
    }

    public function test_invalid_sender_ids_are_rejected(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(SettingsIndex::class)
            ->set('defaultSenderId', 'this-sender-id-is-far-too-long')
            ->call('save')
            ->assertHasErrors('defaultSenderId');
    }

    public function test_an_administrator_can_check_the_provider_balance(): void
    {
        $this->gateway->balanceResult = new \App\Services\Termii\Data\BalanceResult(true, 875.25, 'NGN', 'Balance retrieved.');

        $this->actingAs($this->admin());

        Livewire::test(SettingsIndex::class)
            ->call('checkBalance')
            ->assertSet('providerResult.type', 'success');

        $provider = \App\Models\ProviderStatus::termii();

        $this->assertSame('connected', $provider->status);
        $this->assertEqualsWithDelta(875.25, (float) $provider->balance, 0.001);
    }
}
