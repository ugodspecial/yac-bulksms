<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'password',
            'remember_token' => Str::random(10),
            'role' => UserRole::Operator,
            'permissions' => [],
            'is_active' => true,
            'must_change_password' => false,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Admin,
            'permissions' => [],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * @param  array<int, \App\Enums\Permission>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn () => [
            'permissions' => array_map(fn ($permission) => $permission->value, $permissions),
        ]);
    }

    /**
     * @param  array<int, \App\Enums\Permission>  $permissions
     */
    public function operator(array $permissions = []): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Operator,
            'permissions' => array_map(fn ($permission) => $permission->value, $permissions),
        ]);
    }

    public function needsPasswordChange(): static
    {
        return $this->state(fn () => ['must_change_password' => true]);
    }
}
