<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\ContactImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactImport>
 */
class ContactImportFactory extends Factory
{
    protected $model = ContactImport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'original_filename' => 'contacts.csv',
            'stored_path' => 'imports/contacts.csv',
            'disk' => 'local',
            'file_type' => 'csv',
            'status' => ImportStatus::Pending,
            'max_rows' => 20000,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn () => ['status' => ImportStatus::Ready]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ImportStatus::Completed,
            'finished_at' => now(),
        ]);
    }
}
