<?php

namespace Database\Factories;

use App\Models\ContactGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactGroup>
 */
class ContactGroupFactory extends Factory
{
    protected $model = ContactGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(3, true)),
            'description' => fake()->sentence(),
        ];
    }
}
