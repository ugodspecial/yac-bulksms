<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Services\Termii\TermiiGateway;
use App\Services\Termii\TermiiGatewayInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(TermiiGatewayInterface::class, TermiiGateway::class);
    }

    public function boot(): void
    {
        // Surface mass-assignment mistakes during development and in tests.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->registerGates();
        $this->registerRateLimiters();
        $this->registerViewComposers();
    }

    private function registerGates(): void
    {
        Gate::define('admin', fn (User $user) => $user->isAdmin());
        Gate::define('manage-contacts', fn (User $user) => $user->hasPermission(Permission::ManageContacts));
        Gate::define('import-contacts', fn (User $user) => $user->hasPermission(Permission::ImportContacts));
        Gate::define('send-campaigns', fn (User $user) => $user->hasPermission(Permission::SendCampaigns));
        Gate::define('export-reports', fn (User $user) => $user->hasPermission(Permission::ExportReports));
        Gate::define('manage-suppressions', fn (User $user) => $user->isAdmin());
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(
                strtolower((string) $request->input('email')).'|'.$request->ip()
            );
        });

        // Confirmation, imports and retries are sensitive, money-spending or
        // heavy operations.
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute(20)->by(
            optional($request->user())->getAuthIdentifier() ?: $request->ip()
        ));

        // Termii delivery report webhook: generous, but bounded.
        RateLimiter::for('termii-webhook', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));
    }

    private function registerViewComposers(): void
    {
        View::composer(['layouts.app', 'layouts.guest'], function ($view): void {
            $settings = $this->app->make(SettingsService::class);

            $view->with([
                'appName' => $settings->appName(),
                'appLogo' => $settings->logoPath(),
            ]);
        });
    }
}
