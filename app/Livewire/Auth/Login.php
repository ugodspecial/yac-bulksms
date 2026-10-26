<?php

namespace App\Livewire\Auth;

use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Sign in')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string'],
        ];
    }

    public function login(AuditLogger $audit): void
    {
        $this->validate();

        $key = 'login:'.strtolower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            $this->addError('email', sprintf('Too many sign-in attempts. Please try again in %d seconds.', $seconds));

            return;
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password, 'is_active' => true], $this->remember)) {
            RateLimiter::hit($key, 60);
            $this->addError('email', 'These credentials do not match our records, or the account is deactivated.');
            $this->password = '';

            return;
        }

        RateLimiter::clear($key);
        request()->session()->regenerate();

        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();

        $audit->log('user.logged_in', $user, sprintf('%s signed in.', $user->name));

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.login');
    }
}
