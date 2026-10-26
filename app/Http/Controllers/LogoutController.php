<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $audit->log('user.logged_out', $user, 'Signed out.');
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }
}
