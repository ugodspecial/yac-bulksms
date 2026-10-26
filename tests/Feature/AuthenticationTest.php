<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithSms;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use InteractsWithSms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGateway();
        $this->configureSettings();
    }

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_an_active_user_can_sign_in(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery',
            'is_active' => true,
            'must_change_password' => false,
        ]);

        Livewire::test(Login::class)
            ->set('email', 'admin@example.com')
            ->set('password', 'correct-horse-battery')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_a_deactivated_user_cannot_sign_in(): void
    {
        User::factory()->inactive()->create([
            'email' => 'disabled@example.com',
            'password' => 'correct-horse-battery',
        ]);

        Livewire::test(Login::class)
            ->set('email', 'disabled@example.com')
            ->set('password', 'correct-horse-battery')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_invalid_credentials_are_rejected_without_leaking_details(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery',
        ]);

        Livewire::test(Login::class)
            ->set('email', 'admin@example.com')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_user_with_a_temporary_password_is_forced_to_the_profile_page(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect('/profile');
    }

    public function test_signing_out_ends_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }
}
