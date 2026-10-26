<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithSms;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use InteractsWithSms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGateway();
        $this->configureSettings();
    }

    public function test_operators_cannot_open_administrator_screens(): void
    {
        $operator = $this->operator();

        foreach (['/settings', '/users', '/audit-logs', '/suppressions'] as $url) {
            $this->actingAs($operator)->get($url)->assertForbidden();
        }
    }

    public function test_administrators_can_open_administrator_screens(): void
    {
        $admin = $this->admin();

        foreach (['/settings', '/users', '/audit-logs', '/suppressions'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_operators_can_view_contacts_but_cannot_manage_them_without_permission(): void
    {
        $contact = Contact::factory()->create();

        $operatorWithoutPermission = $this->operator(permissions: []);
        $this->actingAs($operatorWithoutPermission)->get('/contacts')->assertOk();
        $this->assertFalse(Gate::forUser($operatorWithoutPermission)->allows('create', Contact::class));

        $manager = $this->operator(permissions: [Permission::ManageContacts]);
        $this->actingAs($manager)->get('/contacts/' . $contact->id)->assertOk();
        $this->assertTrue(Gate::forUser($manager)->allows('update', $contact));
    }

    public function test_only_users_with_the_send_permission_may_confirm_campaigns(): void
    {
        $campaign = Campaign::factory()->create();

        $this->assertFalse(Gate::forUser($this->operator(permissions: []))->allows('confirm', $campaign));
        $this->assertTrue(Gate::forUser($this->operator(permissions: [Permission::SendCampaigns]))->allows('confirm', $campaign));
        $this->assertTrue(Gate::forUser($this->admin())->allows('confirm', $campaign));
    }

    public function test_only_administrators_can_cancel_or_retry_campaigns(): void
    {
        $campaign = Campaign::factory()->create();

        $sender = $this->operator(permissions: [Permission::SendCampaigns]);

        $this->assertFalse(Gate::forUser($sender)->allows('cancel', $campaign));
        $this->assertFalse(Gate::forUser($sender)->allows('retry', $campaign));
        $this->assertTrue(Gate::forUser($this->admin())->allows('cancel', $campaign));
    }

    public function test_a_draft_cannot_be_edited_after_confirmation(): void
    {
        $admin = $this->admin();
        $campaign = Campaign::factory()->create([
            'created_by' => $admin->id,
            'status' => 'queued',
            'confirmed_at' => now(),
        ]);

        $this->assertFalse(Gate::forUser($admin)->allows('update', $campaign));
    }

    public function test_groups_are_visible_to_everyone_but_only_managed_with_permission(): void
    {
        $group = ContactGroup::factory()->create();

        $this->actingAs($this->operator(permissions: []))->get('/groups/' . $group->id)->assertOk();
        $this->assertFalse(Gate::forUser($this->operator(permissions: []))->allows('update', $group));
        $this->assertTrue(Gate::forUser($this->operator(permissions: [Permission::ManageContacts]))->allows('update', $group));
    }

    public function test_the_settings_screen_requires_an_administrator(): void
    {
        $operator = $this->operator();

        $this->assertFalse(Gate::forUser($operator)->allows('admin'));

        $this->actingAs($operator)->get('/settings')->assertForbidden();

        $this->actingAs($this->admin())->get('/settings')->assertOk();
    }

    public function test_an_operator_cannot_delete_their_own_account_through_the_user_policy(): void
    {
        $operator = $this->admin();

        $this->assertFalse(Gate::forUser($operator)->allows('delete', $operator));
    }
}
