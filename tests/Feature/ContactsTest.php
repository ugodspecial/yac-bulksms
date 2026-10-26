<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Livewire\Contacts\Index as ContactsIndex;
use App\Models\Contact;
use App\Models\SuppressionEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithSms;
use Tests\TestCase;

class ContactsTest extends TestCase
{
    use InteractsWithSms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGateway();
        $this->configureSettings();
    }

    public function test_a_contact_is_created_with_a_normalised_phone_number(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(ContactsIndex::class)
            ->call('openCreate')
            ->set('name', 'Ada Okafor')
            ->set('phone', '0803 123 4567')
            ->set('email', 'ada@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $contact = Contact::query()->firstOrFail();

        $this->assertSame('2348031234567', $contact->phone);
        $this->assertSame('Ada Okafor', $contact->name);
        $this->assertSame(SubscriptionStatus::Subscribed, $contact->subscription_status);
    }

    public function test_duplicate_phone_numbers_are_rejected(): void
    {
        $this->actingAs($this->operator());

        Contact::factory()->create(['phone' => '2348031234567']);

        Livewire::test(ContactsIndex::class)
            ->call('openCreate')
            ->set('phone', '+2348031234567')
            ->call('save')
            ->assertHasErrors('phone');

        $this->assertSame(1, Contact::query()->count());
    }

    public function test_invalid_phone_numbers_are_rejected(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(ContactsIndex::class)
            ->call('openCreate')
            ->set('phone', '12345')
            ->call('save')
            ->assertHasErrors('phone');

        $this->assertSame(0, Contact::query()->count());
    }

    public function test_unsubscribing_a_contact_adds_it_to_the_suppression_list(): void
    {
        $this->actingAs($this->operator());

        $contact = Contact::factory()->create(['phone' => '2348031234567']);

        Livewire::test(ContactsIndex::class)
            ->call('startUnsubscribe', $contact->id)
            ->set('unsubscribeReason', 'Customer request')
            ->call('confirmUnsubscribe')
            ->assertHasNoErrors();

        $contact->refresh();

        $this->assertSame(SubscriptionStatus::Unsubscribed, $contact->subscription_status);
        $this->assertNotNull($contact->unsubscribed_at);

        $this->assertTrue(SuppressionEntry::query()->where('phone', '2348031234567')->exists());
    }

    public function test_creating_a_contact_for_a_suppressed_number_keeps_it_unsubscribed(): void
    {
        $this->actingAs($this->operator());

        SuppressionEntry::factory()->create(['phone' => '2348031234567']);

        Livewire::test(ContactsIndex::class)
            ->call('openCreate')
            ->set('phone', '08031234567')
            ->call('save')
            ->assertHasNoErrors();

        $contact = Contact::query()->firstOrFail();

        $this->assertSame(SubscriptionStatus::Unsubscribed, $contact->subscription_status);
        $this->assertSame('suppression_list', $contact->consent_source);
    }

    public function test_only_administrators_can_resubscribe_a_suppressed_contact(): void
    {
        $contact = Contact::factory()->unsubscribed()->create(['phone' => '2348031234567']);
        SuppressionEntry::factory()->create(['phone' => '2348031234567']);

        $this->actingAs($this->operator());

        try {
            Livewire::test(ContactsIndex::class)->call('resubscribe', $contact->id);
            $this->fail('Operators must not be able to re-subscribe contacts.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            // expected
        }

        $this->assertSame(SubscriptionStatus::Unsubscribed, $contact->fresh()->subscription_status);

        $this->actingAs($this->admin());

        Livewire::test(ContactsIndex::class)->call('resubscribe', $contact->id);

        $this->assertSame(SubscriptionStatus::Subscribed, $contact->fresh()->subscription_status);
        $this->assertFalse(SuppressionEntry::query()->where('phone', '2348031234567')->exists());
    }
}
