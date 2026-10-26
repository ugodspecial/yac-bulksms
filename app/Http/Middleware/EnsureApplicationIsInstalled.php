<?php

namespace App\Http\Middleware;

use App\Services\Install\InstallationManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends every browser request to the installer until the application has been
 * installed, and closes the installer again once it has.
 */
class EnsureApplicationIsInstalled
{
    public function __construct(private readonly InstallationManager $installation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->installation->isInstalled()) {
            // The installer must never be re-runnable once it finished: it can
            // rewrite .env and reset the administrator password.
            if ($this->isInstallerRequest($request) && ! $request->is('install/complete')) {
                return redirect()->route('dashboard');
            }

            return $next($request);
        }

        if (! $this->installation->isEnabled() || $this->isInstallerRequest($request) || $request->is('up')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'YAC-BulkSMS has not been installed yet. Open the application in a browser to run the installer.',
            ], 503);
        }

        return redirect()->route('install.requirements');
    }

    private function isInstallerRequest(Request $request): bool
    {
        return $request->is('install') || $request->is('install/*');
    }
}
