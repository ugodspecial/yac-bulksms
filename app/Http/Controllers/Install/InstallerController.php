<?php

namespace App\Http\Controllers\Install;

use App\Exceptions\InstallException;
use App\Http\Controllers\Controller;
use App\Services\Install\DatabaseInstaller;
use App\Services\Install\DatabaseInstallResult;
use App\Services\Install\EnvFileWriter;
use App\Services\Install\InstallationManager;
use App\Services\Install\InstallationRunner;
use App\Services\Install\RequirementsChecker;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Two-step browser installer: configure the database (created automatically),
 * then the application and the first administrator.
 *
 * Nothing here touches the database until the last button is pressed, so the
 * installer works on a machine where no database exists yet.
 */
class InstallerController extends Controller
{
    public function __construct(
        private readonly InstallationManager $installation,
        private readonly RequirementsChecker $requirements,
    ) {}

    public function requirements(): View|RedirectResponse
    {
        return view('install.requirements', [
            'checks' => $this->requirements->checks(),
            'passes' => $this->requirements->passes(),
            'problems' => (array) session('install_problems', []),
            'enabled' => $this->installation->isEnabled(),
        ]);
    }

    public function database(): View
    {
        $connection = (string) config('database.default');

        return view('install.database', [
            'driver' => in_array($connection, ['mysql', 'sqlite'], true) ? $connection : 'sqlite',
            'defaults' => [
                'host' => (string) (config('database.connections.mysql.host') ?: '127.0.0.1'),
                'port' => (string) (config('database.connections.mysql.port') ?: '3306'),
                'database' => (string) (config('database.connections.mysql.database') ?: 'yac_bulksms'),
                'username' => (string) (config('database.connections.mysql.username') ?: 'root'),
            ],
            'sqlitePath' => (string) (config('database.connections.sqlite.database') ?: database_path('database.sqlite')),
            'summary' => $this->databaseSummary(),
        ]);
    }

    public function storeDatabase(Request $request, DatabaseInstaller $installer, EnvFileWriter $env): RedirectResponse
    {
        $data = $request->validate([
            'db_driver' => ['required', Rule::in(['sqlite', 'mysql'])],
            'db_host' => ['nullable', 'string', 'max:190'],
            'db_port' => ['nullable', 'integer', 'between:1,65535'],
            'db_database' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]{1,64}$/'],
            'db_username' => ['nullable', 'string', 'max:190'],
            'db_password' => ['nullable', 'string', 'max:190'],
            'db_sqlite_path' => ['nullable', 'string', 'max:400', 'ends_with:.sqlite,.sqlite3,.db'],
        ], [
            'db_database.regex' => 'The database name may only contain letters, numbers and underscores.',
            'db_sqlite_path.ends_with' => 'The SQLite file name must end with .sqlite, .sqlite3 or .db.',
        ], [
            'db_host' => 'database host',
            'db_port' => 'database port',
            'db_database' => 'database name',
            'db_username' => 'database username',
            'db_password' => 'database password',
            'db_sqlite_path' => 'SQLite file path',
        ]);

        $driver = (string) $data['db_driver'];

        if ($driver === 'mysql') {
            $request->validate([
                'db_host' => ['required', 'string', 'max:190'],
                'db_port' => ['required', 'integer', 'between:1,65535'],
                'db_database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]{1,64}$/'],
                'db_username' => ['required', 'string', 'max:190'],
            ], [
                'db_database.regex' => 'The database name may only contain letters, numbers and underscores.',
            ], [
                'db_host' => 'database host',
                'db_port' => 'database port',
                'db_database' => 'database name',
                'db_username' => 'database username',
            ]);
        }

        $settings = [
            'driver' => $driver,
            'host' => (string) ($data['db_host'] ?? '127.0.0.1'),
            'port' => (int) ($data['db_port'] ?? 3306),
            'database' => $driver === 'mysql'
                ? ($data['db_database'] ?? null)
                : (filled($data['db_sqlite_path'] ?? null) ? trim((string) $data['db_sqlite_path']) : null),
            'username' => (string) ($data['db_username'] ?? ''),
            'password' => (string) ($data['db_password'] ?? ''),
        ];

        $result = $installer->install($settings);

        if (! $result->ok) {
            return $this->backTo('install.database', $request, $result->message, $driver === 'mysql' ? 'db_database' : 'db_sqlite_path');
        }

        try {
            $env->write($this->installation->envPath(), $this->databaseEnvironment($settings, $result));
        } catch (InstallException $e) {
            return $this->backTo('install.database', $request, $e->getMessage(), 'db_database');
        }

        $this->installation->forgetConfigurationCache();

        // The next request boots with the freshly written .env, so the
        // application step can talk to the database we just created.
        return redirect()->route('install.application');
    }

    public function application(): View
    {
        return view('install.application', [
            'timezones' => DateTimeZone::listIdentifiers(),
            'defaults' => [
                'app_name' => (string) config('app.name', 'YAC-BulkSMS'),
                'app_url' => rtrim(request()->getSchemeAndHttpHost(), '/'),
                'timezone' => (string) config('app.timezone', 'Africa/Lagos'),
                'environment' => request()->isSecure() ? 'production' : 'local',
                'sender_id' => (string) config('termii.sender_id', ''),
                'termii_configured' => filled(config('termii.api_key')),
            ],
            'summary' => $this->databaseSummary(),
        ]);
    }

    public function storeApplication(
        Request $request,
        EnvFileWriter $env,
        InstallationRunner $runner,
    ): RedirectResponse {
        $data = $request->validate([
            'app_name' => ['required', 'string', 'max:60'],
            'app_url' => ['required', 'url', 'max:190'],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'environment' => ['required', Rule::in(['local', 'production'])],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:190'],
            'admin_password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
            'termii_api_key' => ['nullable', 'string', 'max:200'],
            'termii_sender_id' => ['nullable', 'string', 'min:3', 'max:11', 'regex:/^[A-Za-z0-9 ]+$/'],
        ], [
            'termii_sender_id.regex' => 'Sender IDs may only contain letters, numbers and spaces.',
        ], [
            'app_name' => 'application name',
            'app_url' => 'application URL',
            'admin_name' => 'administrator name',
            'admin_email' => 'administrator email',
            'admin_password' => 'administrator password',
            'termii_api_key' => 'Termii API key',
            'termii_sender_id' => 'sender ID',
        ]);

        $values = [
            'APP_NAME' => trim((string) $data['app_name']),
            'APP_ENV' => (string) $data['environment'],
            'APP_DEBUG' => $data['environment'] === 'local' ? 'true' : 'false',
            'APP_URL' => rtrim((string) $data['app_url'], '/'),
            'APP_TIMEZONE' => (string) $data['timezone'],
        ];

        // Empty Termii fields keep whatever is already configured.
        if (filled($data['termii_api_key'] ?? null)) {
            $values['TERMII_API_KEY'] = trim((string) $data['termii_api_key']);
        }

        if (filled($data['termii_sender_id'] ?? null)) {
            $values['TERMII_SENDER_ID'] = trim((string) $data['termii_sender_id']);
        }

        try {
            $env->write($this->installation->envPath(), $values);
        } catch (InstallException $e) {
            return $this->backTo('install.application', $request, $e->getMessage(), 'app_name');
        }

        $this->installation->forgetConfigurationCache();

        $result = $runner->complete([
            'name' => (string) $data['admin_name'],
            'email' => (string) $data['admin_email'],
            'password' => (string) $data['admin_password'],
        ]);

        if (! $result['ok']) {
            return $this->backTo('install.application', $request, $result['message'], 'app_name');
        }

        return redirect()->route('install.complete');
    }

    public function skip(InstallationRunner $runner): RedirectResponse
    {
        $verification = $runner->verifyExistingEnvironment();

        if (! $verification['ok']) {
            return redirect()
                ->route('install.requirements')
                ->with('install_problems', $verification['problems']);
        }

        $runner->markSkipped();

        return redirect()->route('install.complete');
    }

    public function complete(): View|RedirectResponse
    {
        if (! $this->installation->isInstalled()) {
            return redirect()->route('install.requirements');
        }

        $state = $this->installation->state() ?? [];

        return view('install.complete', [
            'skipped' => (bool) ($state['skipped'] ?? false),
            'installedAt' => $state['installed_at'] ?? null,
            'database' => $state['database'] ?? (string) config('database.default'),
            'queue' => (string) config('queue.default'),
            'webhookEnabled' => (bool) config('termii.webhook.enabled'),
            'loginUrl' => route('login'),
        ]);
    }

    /**
     * @param  array{driver: string, host: string|null, port: int|string|null, database: string|null, username: string, password: string}  $settings
     * @return array<string, string|null>
     */
    private function databaseEnvironment(array $settings, DatabaseInstallResult $result): array
    {
        $values = [
            'APP_KEY' => $this->applicationKey(),
            'DB_CONNECTION' => $result->driver,
            // Neutralise a stale DB_URL that would override the values below.
            'DB_URL' => null,
        ];

        if ($result->driver === 'mysql') {
            $values['DB_HOST'] = (string) $settings['host'];
            $values['DB_PORT'] = (string) $settings['port'];
            $values['DB_DATABASE'] = (string) $result->database;
            $values['DB_USERNAME'] = (string) $settings['username'];
            $values['DB_PASSWORD'] = (string) $settings['password'];
        } else {
            $values['DB_DATABASE'] = (string) $result->database;
        }

        // Session, cache and queue use the database by default: no extra
        // services are required to run the application.
        $values['SESSION_DRIVER'] = 'database';
        $values['CACHE_STORE'] = 'database';
        $values['QUEUE_CONNECTION'] = 'database';

        return $values;
    }

    /**
     * Reuse an existing key (for example from a copied .env) but never the
     * temporary key generated for the installer's own cookies.
     */
    private function applicationKey(): string
    {
        $configured = trim((string) config('app.key'));
        $temporaryPath = storage_path('framework/installer.key');
        $temporary = is_file($temporaryPath) ? trim((string) @file_get_contents($temporaryPath)) : '';

        if ($configured !== '' && ($temporary === '' || $configured !== $temporary)) {
            return $configured;
        }

        return 'base64:'.base64_encode(random_bytes(32));
    }

    /**
     * @return array{driver: string, label: string}|null
     */
    private function databaseSummary(): ?array
    {
        $driver = (string) config('database.default');

        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            return null;
        }

        $connection = (array) config('database.connections.'.$driver, []);
        $database = (string) ($connection['database'] ?? '');

        if ($driver === 'sqlite') {
            $database = app(DatabaseInstaller::class)->absolutePath($database);
        }

        $configured = $driver === 'sqlite' ? is_file($database) : $database !== '' && $database !== 'yac_bulksms';

        if (! $configured) {
            return null;
        }

        return [
            'driver' => $driver,
            'label' => $driver === 'sqlite'
                ? 'SQLite · '.$database
                : sprintf('MySQL · %s@%s/%s', (string) ($connection['username'] ?? ''), (string) ($connection['host'] ?? ''), $database),
        ];
    }

    private function backTo(string $route, Request $request, string $message, string $field): RedirectResponse
    {
        return redirect()
            ->route($route)
            ->withInput($request->except(['db_password', 'admin_password', 'admin_password_confirmation', 'termii_api_key']))
            ->withErrors([$field => $message]);
    }
}
