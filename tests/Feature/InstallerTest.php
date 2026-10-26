<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithSms;
use Tests\TestCase;

/**
 * The browser installer: every page must render before a database exists, and
 * the final step must create the schema, the settings and the administrator.
 */
class InstallerTest extends TestCase
{
    use InteractsWithSms;
    use RefreshDatabase;

    private string $tempDir;

    private string $envPath;

    private string $markerPath;

    private string $originalEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/yac-installer-'.Str::random(10);
        $this->envPath = $this->tempDir.'/.env';
        $this->markerPath = $this->tempDir.'/installation.json';

        mkdir($this->tempDir, 0777, true);

        $this->originalEnv = implode("\n", [
            '# Existing environment file',
            'APP_NAME=Old name',
            'DB_CONNECTION=sqlite',
            'DB_DATABASE=keep-me',
            '',
        ]);

        file_put_contents($this->envPath, $this->originalEnv);

        config([
            'yac.installer.enabled' => true,
            'yac.installer.marker' => $this->markerPath,
            'yac.installer.env_path' => $this->envPath,
        ]);

        $this->fakeGateway();
        $this->configureSettings();
    }

    protected function tearDown(): void
    {
        $files = array_merge(
            glob($this->tempDir.'/*') ?: [],
            glob($this->tempDir.'/.*') ?: [],
        );

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->tempDir);

        parent::tearDown();
    }

    public function test_visitors_are_sent_to_the_installer_until_it_has_run(): void
    {
        $this->get('/dashboard')->assertRedirectToRoute('install.requirements');
        $this->get('/')->assertRedirectToRoute('install.requirements');
        $this->get('/login')->assertRedirectToRoute('install.requirements');
    }

    public function test_every_installer_page_renders_without_a_database(): void
    {
        $this->get('/install')->assertOk()->assertSee('Server requirements');
        $this->get('/install/database')->assertOk()->assertSee('MySQL / MariaDB');
        $this->get('/install/application')->assertOk()->assertSee('First administrator');
    }

    public function test_the_finish_page_requires_an_installation(): void
    {
        $this->get('/install/complete')->assertRedirectToRoute('install.requirements');
    }

    public function test_the_sqlite_database_is_created_and_written_to_the_environment_file(): void
    {
        $database = $this->tempDir.'/created.sqlite';

        $this->post('/install/database', [
            'db_driver' => 'sqlite',
            'db_sqlite_path' => $database,
        ])->assertRedirectToRoute('install.application');

        $this->assertFileExists($database);

        $env = (string) file_get_contents($this->envPath);

        $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
        $this->assertStringContainsString('created.sqlite', $env);
        $this->assertStringNotContainsString('DB_DATABASE=keep-me', $env);
        $this->assertStringContainsString('SESSION_DRIVER=database', $env);
        $this->assertStringContainsString('QUEUE_CONNECTION=database', $env);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{40,}$/m', $env);

        // Unmanaged keys and comments survive.
        $this->assertStringContainsString('# Existing environment file', $env);
        $this->assertStringContainsString('APP_NAME=Old name', $env);
    }

    public function test_a_mysql_connection_requires_a_safe_database_name(): void
    {
        $this->post('/install/database', [
            'db_driver' => 'mysql',
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_database' => 'bad name; drop database users',
            'db_username' => 'root',
        ])->assertSessionHasErrors('db_database');

        $this->assertSame($this->originalEnv, (string) file_get_contents($this->envPath));
    }

    public function test_an_unreachable_mysql_server_shows_a_helpful_error(): void
    {
        $this->post('/install/database', [
            'db_driver' => 'mysql',
            'db_host' => '127.0.0.1',
            'db_port' => '1',
            'db_database' => 'yac_bulksms',
            'db_username' => 'nobody',
            'db_password' => 'nothing',
        ])->assertSessionHasErrors('db_database');

        $this->assertStringContainsString(
            'DB_CONNECTION=sqlite',
            (string) file_get_contents($this->envPath),
        );
    }

    public function test_the_application_step_migrates_and_creates_the_administrator(): void
    {
        $this->post('/install/application', [
            'app_name' => 'YAC Messaging',
            'app_url' => 'https://sms.example.test',
            'timezone' => 'Africa/Lagos',
            'environment' => 'production',
            'admin_name' => 'Ada Admin',
            'admin_email' => 'ada@example.test',
            'admin_password' => 'SecretPass123',
            'admin_password_confirmation' => 'SecretPass123',
            'termii_api_key' => 'installer-secret-key',
            'termii_sender_id' => 'YAC',
        ])->assertRedirectToRoute('install.complete');

        $this->assertFileExists($this->markerPath);

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.test',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->assertTrue(Auth::validate([
            'email' => 'ada@example.test',
            'password' => 'SecretPass123',
        ]));

        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseHas('settings', ['key' => 'app.name']);

        $env = (string) file_get_contents($this->envPath);

        $this->assertStringContainsString('APP_NAME=\'YAC Messaging\'', $env);
        $this->assertStringContainsString('APP_ENV=production', $env);
        $this->assertStringContainsString('APP_DEBUG=false', $env);
        $this->assertStringContainsString('APP_URL=https://sms.example.test', $env);
        $this->assertStringContainsString('TERMII_API_KEY=installer-secret-key', $env);
        $this->assertStringContainsString('TERMII_SENDER_ID=YAC', $env);

        // Credentials of the administrator never leave the database.
        $this->assertStringNotContainsString('SecretPass123', $env);
    }

    public function test_the_administrator_password_must_be_strong_and_confirmed(): void
    {
        $this->post('/install/application', [
            'app_name' => 'YAC',
            'app_url' => 'https://sms.example.test',
            'timezone' => 'Africa/Lagos',
            'environment' => 'production',
            'admin_name' => 'Ada Admin',
            'admin_email' => 'ada@example.test',
            'admin_password' => 'short',
            'admin_password_confirmation' => 'different',
        ])->assertSessionHasErrors('admin_password');

        $this->assertFileDoesNotExist($this->markerPath);
        $this->assertSame(0, User::query()->count());
    }

    public function test_the_installer_is_closed_once_it_has_finished(): void
    {
        file_put_contents($this->markerPath, json_encode(['installed_at' => now()->toIso8601String()]));

        $this->get('/install')->assertRedirectToRoute('dashboard');
        $this->get('/install/database')->assertRedirectToRoute('dashboard');

        $this->actingAs($this->admin())->get('/dashboard')->assertOk();
        $this->get('/install/complete')->assertOk()->assertSee('Installation complete');
    }

    public function test_skipping_requires_a_working_configuration(): void
    {
        // Simulate a database that has not been migrated yet. SQLite DDL is
        // transactional, so the table comes back with the test transaction.
        Schema::drop('settings');

        $this->post('/install/skip')
            ->assertRedirectToRoute('install.requirements')
            ->assertSessionHas('install_problems');

        $this->assertFileDoesNotExist($this->markerPath);
    }

    public function test_an_unwritable_sqlite_location_is_reported_instead_of_thrown(): void
    {
        $blocker = $this->tempDir.'/not-a-directory';
        file_put_contents($blocker, 'this file is in the way');

        $result = app(\App\Services\Install\DatabaseInstaller::class)->install([
            'driver' => 'sqlite',
            'database' => $blocker.'/database.sqlite',
        ]);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('directory', $result->message);
    }

    public function test_skipping_marks_the_application_installed_when_the_configuration_works(): void
    {
        $this->post('/install/skip')->assertRedirectToRoute('install.complete');

        $this->assertFileExists($this->markerPath);

        $state = json_decode((string) file_get_contents($this->markerPath), true);

        $this->assertTrue($state['skipped']);
        $this->assertSame('sqlite', $state['database']);

        $this->actingAs($this->admin())->get('/dashboard')->assertOk();
    }

    public function test_the_installer_can_be_disabled_for_environment_based_deployments(): void
    {
        config(['yac.installer.enabled' => false]);

        $this->actingAs($this->admin())->get('/dashboard')->assertOk();
    }
}
