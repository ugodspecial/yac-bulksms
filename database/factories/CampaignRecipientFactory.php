<?php

namespace Database\Factories;

use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignRecipient>
 */
class CampaignRecipientFactory extends Factory
{
    protected $model = CampaignRecipient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'phone' => '234803'.fake()->unique()->numerify('#######'),
            'name' => fake()->name(),
            'rendered_message' => 'Hello from YAC.',
            'segments' => 1,
            'status' => RecipientStatus::Pending,
            'provider' => 'termii',
            'attempts' => 0,
            'ambiguous' => false,
        ];
    }

    public function accepted(?string $messageId = null): static
    {
        return $this->state(fn () => [
            'status' => RecipientStatus::Accepted,
            'provider_message_id' => $messageId ?? 'msg-'.fake()->uuid(),
            'provider_status' => 'accepted',
            'submitted_at' => now(),
        ]);
    }

    public function delivered(?string $messageId = null): static
    {
        return $this->state(fn () => [
            'status' => RecipientStatus::Delivered,
            'provider_message_id' => $messageId ?? 'msg-'.fake()->uuid(),
            'provider_status' => 'Delivered',
            'submitted_at' => now()->subMinute(),
            'delivered_at' => now(),
        ]);
    }

    public function failed(string $errorCode = 'server_error'): static
    {
        return $this->state(fn () => [
            'status' => RecipientStatus::Failed,
            'error_code' => $errorCode,
            'error_message' => 'Termii rejected the message.',
            'attempts' => 1,
            'failed_at' => now(),
        ]);
    }

    public function unknown(): static
    {
        return $this->state(fn () => [
            'status' => RecipientStatus::Unknown,
            'ambiguous' => true,
            'provider_status' => 'ambiguous',
            'error_code' => 'ambiguous_timeout',
            'error_message' => 'The request timed out with an unknown outcome.',
            'attempts' => 1,
        ]);
    }
}
