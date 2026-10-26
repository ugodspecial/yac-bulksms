<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates the first administrator from the ADMIN_* environment variables.
 * No demo contacts, campaigns or messages are seeded: statistics in this
 * application must always reflect real activity.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) config('yac.admin.email', 'admin@yac-bulksms.test')));

        if ($email === '') {
            $this->command?->warn('ADMIN_EMAIL is empty; skipping the administrator seeder.');

            return;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            $this->command?->info(sprintf('Administrator %s already exists; leaving it untouched.', $email));

            return;
        }

        $password = (string) config('yac.admin.password', '');
        $generated = false;

        if ($password === '') {
            // Never fall back to a well-known default: generate a strong one and
            // print it exactly once so the operator can sign in and change it.
            $password = Str::password(20);
            $generated = true;
        }

        User::create([
            'name' => (string) config('yac.admin.name', 'YAC Administrator'),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => UserRole::Admin,
            'permissions' => [],
            'is_active' => true,
            'must_change_password' => true,
        ]);

        $this->command?->info(sprintf('Created the administrator account %s.', $email));

        if ($generated) {
            $this->command?->warn(sprintf('ADMIN_PASSWORD was not set. Generated password: %s', $password));
            $this->command?->warn('Store it somewhere safe now: it is not shown again. You will be asked to change it at first sign-in.');
        }
    }
}
