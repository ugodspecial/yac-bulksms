<?php

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Enums\DispatchMode;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->words(3, true)),
            'message' => 'Hello, this is a test message from YAC.',
            'sender_id' => 'YAC',
            'channel' => 'generic',
            'dispatch_mode' => DispatchMode::Bulk,
            'status' => CampaignStatus::Draft,
            'encoding' => 'gsm-7',
            'max_segments' => 1,
            'audience' => ['contact_ids' => [], 'group_ids' => []],
            'batch_size' => 100,
        ];
    }

    public function forUser(?User $user = null): static
    {
        return $this->state(fn () => [
            'created_by' => $user?->id ?? User::factory(),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Queued,
            'confirmed_at' => now(),
            'queued_at' => now(),
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Scheduled,
            'scheduled_at' => now()->addHour(),
            'confirmed_at' => now(),
        ]);
    }
}
