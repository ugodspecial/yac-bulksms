<?php

namespace Tests\Concerns;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Services\Termii\TermiiGatewayInterface;
use Tests\Support\FakeTermiiGateway;

trait InteractsWithSms
{
    protected FakeTermiiGateway $gateway;

    protected function fakeGateway(): FakeTermiiGateway
    {
        $this->gateway = new FakeTermiiGateway();

        $this->app->instance(TermiiGatewayInterface::class, $this->gateway);

        return $this->gateway;
    }

    protected function configureSettings(array $overrides = []): void
    {
        /** @var SettingsService $settings */
        $settings = $this->app->make(SettingsService::class);

        $settings->putMany(array_merge([
            'app.name' => 'YAC-BulkSMS',
            'messaging.default_sender_id' => 'YAC',
            'messaging.approved_sender_ids' => ['YAC'],
            'messaging.default_channel' => 'generic',
            'messaging.batch_size' => 100,
            'messaging.max_message_length' => 1000,
            'messaging.max_segments' => 8,
            'contacts.default_country' => 'NG',
            'sending.paused' => false,
            'provider.reconcile_enabled' => true,
        ], $overrides));
    }

    protected function admin(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => UserRole::Admin,
            'is_active' => true,
            'must_change_password' => false,
        ], $overrides));
    }

    /**
     * @param  array<int, Permission>  $permissions
     */
    protected function operator(array $permissions = [Permission::SendCampaigns]): User
    {
        return User::factory()->create([
            'role' => UserRole::Operator,
            'permissions' => array_map(fn (Permission $permission) => $permission->value, $permissions),
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    protected function confirmedCampaign(User $actor, array $attributes = []): Campaign
    {
        $campaign = Campaign::factory()->create(array_merge([
            'created_by' => $actor->id,
            'sender_id' => 'YAC',
            'message' => 'Hello, this is a YAC test message.',
            'status' => 'draft',
            'audience' => ['contact_ids' => [], 'group_ids' => []],
        ], $attributes));

        return $campaign;
    }
}
