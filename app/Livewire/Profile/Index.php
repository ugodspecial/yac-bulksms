<?php

namespace App\Livewire\Profile;

use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('My profile')]
class Index extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function updatePassword(AuditLogger $audit): void
    {
        $user = auth()->user();

        $this->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        if (! Hash::check($this->current_password, $user->password)) {
            $this->addError('current_password', 'The current password is incorrect.');

            return;
        }

        $user->forceFill([
            'password' => Hash::make($this->password),
            'must_change_password' => false,
        ])->save();

        $this->reset('current_password', 'password', 'password_confirmation');

        $audit->log('user.password_changed', $user, 'Changed their own password.');

        $this->dispatch('notify', message: 'Your password has been updated.', type: 'success');
    }

    public function render(): View
    {
        return view('livewire.profile.index', [
            'user' => auth()->user(),
        ]);
    }
}
