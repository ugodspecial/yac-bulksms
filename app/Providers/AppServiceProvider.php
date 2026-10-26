<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Install\InstallationManager;
use App\Services\Settings\SettingsService;
use App\Services\Termii\TermiiGateway;
use App\Services\Termii\TermiiGatewayInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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
        $this->app->singleton(InstallationManager::class);
    }

    public function boot(): void
    {
        $this->prepareInstallerEnvironment();

        // Surface mass-assignment mistakes during development and in tests.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->registerGates();
        $this->registerRateLimiters();
        $this->registerViewComposers();
        $this->warnWhenInstallerIsStillOpen();
    }

    /**
     * Before the application is installed there may be no database, no .env
     * and no APP_KEY. Sessions and the cache are moved to the filesystem so the
     * installer can render forms (and pass its CSRF check), and a stable
     * temporary key keeps cookies readable between requests.
     */
    private function prepareInstallerEnvironment(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        /** @var InstallationManager $installation */
        $installation = $this->app->make(InstallationManager::class);

        if (! $installation->shouldRunInstaller()) {
            return;
        }

        config([
            'session.driver' => is_writable(storage_path('framework/sessions')) ? 'file' : 'array',
            'cache.default' => is_writable(storage_path('framework/cache')) ? 'file' : 'array',
        ]);

        $installation->ensureApplicationKey();
    }

    /**
     * Migrating by hand leaves the browser installer open, which would let
     * anyone who reaches the site create an administrator. Warn loudly.
     */
    private function warnWhenInstallerIsStillOpen(): void
    {
        Event::listen(MigrationsEnded::class, function (MigrationsEnded $event): void {
            if (! $this->app->runningInConsole() || $event->method !== 'up') {
                return;
            }

            $installation = $this->app->make(InstallationManager::class);

            if (! $installation->shouldRunInstaller()) {
                return;
            }

            fwrite(STDERR, PHP_EOL.'  Warning: the browser installer is still open.'
                .PHP_EOL.'  Finish it in the browser, or set YAC_INSTALLER_ENABLED=false, so nobody else can create an administrator.'
                .PHP_EOL.PHP_EOL);
        });
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
