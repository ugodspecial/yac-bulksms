<?php

namespace App\Services\Install;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Performs the destructive part of the installation: creating the tables,
 * writing the default settings, creating the first administrator and closing
 * the installer.
 */
class InstallationRunner
{
    public function __construct(
        private readonly InstallationManager $installation,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string}  $administrator
     * @return array{ok: bool, message: string, administrator: string|null}
     */
    public function complete(array $administrator): array
    {
        // Large imports and slow shared hosts need a little more than the
        // default 30 seconds for the first migration.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            $migrationOutput = trim(Artisan::output());

            // Default settings are only written when a key is still empty, so
            // this is safe even when the installer is re-run.
            $this->settings->forget();
            Artisan::call('db:seed', [
                '--class' => SettingsSeeder::class,
                '--force' => true,
            ]);
            $this->settings->forget();

            $user = $this->createAdministrator($administrator);

            $this->installation->markInstalled([
                'skipped' => false,
                'database' => (string) config('database.default'),
                'administrator' => $user->email,
            ]);

            $this->audit->log('installation.completed', null, sprintf(
                'Installed the application and created the administrator %s.',
                $user->email,
            ), ['administrator' => $user->email]);

            return [
                'ok' => true,
                'message' => $migrationOutput !== '' ? $migrationOutput : 'The database is up to date.',
                'administrator' => $user->email,
            ];
        } catch (Throwable $e) {
            Log::error('The web installer could not finish', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [
                'ok' => false,
                'message' => 'The installation could not be completed: '.Str::limit($e->getMessage(), 400),
                'administrator' => null,
            ];
        }
    }

    /**
     * Checks that an environment configured by hand (server variables or an
     * existing .env) is actually usable before the installer steps aside.
     *
     * @return array{ok: bool, problems: array<int, string>}
     */
    public function verifyExistingEnvironment(): array
    {
        $problems = [];

        if (blank(config('app.key'))) {
            $problems[] = 'APP_KEY is empty. Add one to your environment or let the installer generate it.';
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $problems[] = 'The database connection failed: '.Str::limit($e->getMessage(), 200);

            return ['ok' => false, 'problems' => $problems];
        }

        $tables = ['users', 'campaigns', 'settings'];

        if (config('session.driver') === 'database') {
            $tables[] = 'sessions';
        }

        if (config('cache.default') === 'database') {
            $tables[] = 'cache';
        }

        if (config('queue.default') === 'database') {
            $tables[] = 'jobs';
        }

        foreach (array_unique($tables) as $table) {
            if (! Schema::hasTable($table)) {
                $problems[] = sprintf('The "%s" table is missing. Run "php artisan migrate --force".', $table);
            }
        }

        return ['ok' => $problems === [], 'problems' => $problems];
    }

    public function markSkipped(): void
    {
        $this->installation->markInstalled([
            'skipped' => true,
            'database' => (string) config('database.default'),
        ]);

        $this->audit->log('installation.skipped', null, 'The web installer was skipped; the existing environment configuration is in use.');
    }

    /**
     * @param  array{name: string, email: string, password: string}  $administrator
     */
    private function createAdministrator(array $administrator): User
    {
        $email = strtolower(trim($administrator['email']));

        // Re-running the installer must not create a second administrator with
        // the same address: it resets the password instead.
        $user = User::query()->firstOrNew(['email' => $email]);

        $user->forceFill([
            'name' => trim($administrator['name']),
            'password' => $administrator['password'],
            'role' => UserRole::Admin,
            'permissions' => [],
            'is_active' => true,
            'must_change_password' => false,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        $user->save();

        return $user;
    }
}
