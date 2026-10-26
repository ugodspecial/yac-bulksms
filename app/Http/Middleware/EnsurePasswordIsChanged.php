<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->must_change_password) {
            if (! $request->routeIs('profile') && ! $request->routeIs('logout')) {
                return redirect()
                    ->route('profile')
                    ->with('warning', 'Please change your password before continuing.');
            }
        }

        return $next($request);
    }
}
