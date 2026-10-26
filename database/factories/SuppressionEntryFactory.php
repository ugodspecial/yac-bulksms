<?php

namespace Database\Factories;

use App\Models\SuppressionEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SuppressionEntry>
 */
class SuppressionEntryFactory extends Factory
{
    protected $model = SuppressionEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone' => '234803'.fake()->unique()->numerify('#######'),
            'reason' => 'Customer complaint',
            'source' => 'manual',
        ];
    }
}
