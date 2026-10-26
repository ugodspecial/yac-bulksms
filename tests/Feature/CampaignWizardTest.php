<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\DispatchMode;
use App\Enums\RecipientStatus;
use App\Jobs\DispatchCampaignJob;
use App\Livewire\Campaigns\Wizard;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithSms;
use Tests\TestCase;

class CampaignWizardTest extends TestCase
{
    use InteractsWithSms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->fakeGateway();
        $this->configureSettings();
    }

    public function test_an_operator_can_build_review_and_send_a_campaign(): void
    {
        $operator = $this->operator();
        $this->actingAs($operator);

        $group = ContactGroup::factory()->create();
        $contacts = Contact::factory()->count(2)->create();
        $group->contacts()->attach($contacts->pluck('id'));

        Livewire::test(Wizard::class)
            ->assertSet('step', 1)
            ->set('name', 'October customer update')
            ->set('message', 'Hello {{name}}, our October offer is live.')
            ->set('sender_id', 'YAC')
            ->set('channel', 'generic')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('step', 2)
            ->set('groupIds', [$group->id])
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('step', 3)
            ->assertSet('review.recipients', 2)
            ->set('confirmed', true)
            ->call('confirmSend')
            ->assertHasNoErrors();

        $campaign = Campaign::query()->firstOrFail();

        $this->assertSame(CampaignStatus::Queued, $campaign->status);
        $this->assertSame(2, $campaign->total_recipients);
        $this->assertSame(DispatchMode::Individual, $campaign->dispatch_mode);
        $this->assertSame($operator->id, $campaign->created_by);

        $this->assertSame(2, $campaign->recipients()->where('status', RecipientStatus::Pending->value)->count());

        $rendered = $campaign->recipients()->first()->rendered_message;
        $this->assertStringContainsString('our October offer is live', $rendered);
        $this->assertStringNotContainsString('{{name}}', $rendered);

        Queue::assertPushed(DispatchCampaignJob::class);
    }

    public function test_confirmation_requires_the_acknowledgement_checkbox(): void
    {
        $operator = $this->operator();
        $this->actingAs($operator);

        $contact = Contact::factory()->create();

        Livewire::test(Wizard::class)
            ->set('name', 'No acknowledgement')
            ->set('message', 'Hello there')
            ->set('sender_id', 'YAC')
            ->call('nextStep')
            ->set('contactIds', [$contact->id])
            ->call('nextStep')
            ->assertSet('step', 3)
            ->call('confirmSend')
            ->assertHasErrors('confirmed');

        $this->assertSame(0, \App\Models\CampaignRecipient::query()->count());
    }

    public function test_an_operator_without_send_permission_can_save_a_draft_but_not_confirm(): void
    {
        $operator = $this->operator(permissions: []);
        $this->actingAs($operator);

        $contact = Contact::factory()->create();

        $component = Livewire::test(Wizard::class)
            ->set('name', 'Draft only')
            ->set('message', 'Hello there')
            ->set('sender_id', 'YAC')
            ->call('nextStep')
            ->set('contactIds', [$contact->id])
            ->call('nextStep')
            ->assertSet('step', 3)
            ->set('confirmed', true);

        try {
            $component->call('confirmSend');
            $this->fail('An operator without the send permission must not be able to confirm a campaign.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            // expected: the policy blocks confirmation
        }

        $campaign = Campaign::query()->firstOrFail();

        $this->assertSame(CampaignStatus::Draft, $campaign->status);
        $this->assertSame(0, $campaign->recipients()->count());

        $component->call('submitForReview')->assertHasNoErrors();

        $this->assertSame(CampaignStatus::NeedsReview, $campaign->fresh()->status);
    }

    public function test_the_wizard_rejects_unknown_placeholders(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(Wizard::class)
            ->set('name', 'Bad placeholder')
            ->set('message', 'Hello {{first_name}}')
            ->set('sender_id', 'YAC')
            ->call('nextStep')
            ->assertHasErrors('message')
            ->assertSet('step', 1);
    }

    public function test_the_wizard_excludes_unsubscribed_contacts_from_the_review(): void
    {
        $this->actingAs($this->admin());

        $contact = Contact::factory()->create();
        $unsubscribed = Contact::factory()->unsubscribed()->create();

        Livewire::test(Wizard::class)
            ->set('name', 'Filtered audience')
            ->set('message', 'Hello everyone')
            ->set('sender_id', 'YAC')
            ->call('nextStep')
            ->set('contactIds', [$contact->id, $unsubscribed->id])
            ->call('nextStep')
            ->assertSet('step', 3)
            ->assertSet('review.recipients', 1)
            ->assertSet('review.excluded_count', 1);
    }
}
