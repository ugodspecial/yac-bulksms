<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '234803'.fake()->unique()->numerify('#######'),
            'email' => null,
            'custom_fields' => null,
            'subscription_status' => SubscriptionStatus::Subscribed,
            'subscribed_at' => now(),
            'consent_source' => 'test',
            'source' => 'test',
        ];
    }

    public function unsubscribed(): static
    {
        return $this->state(fn () => [
            'subscription_status' => SubscriptionStatus::Unsubscribed,
            'subscribed_at' => null,
            'unsubscribed_at' => now(),
            'unsubscribe_reason' => 'Test unsubscribe',
        ]);
    }

    /**
     * @param  array<string, string>  $fields
     */
    public function withCustomFields(array $fields): static
    {
        return $this->state(fn () => ['custom_fields' => $fields]);
    }

    public function phone(string $digits): static
    {
        return $this->state(fn () => ['phone' => $digits]);
    }
}
