<?php

namespace App\Services\Install;

use Throwable;

/**
 * Knows whether the application has been installed and where the installer
 * keeps its files.
 *
 * The "marker" is a small JSON file written once the installer (or the
 * operator, by skipping it) has finished. Request handling is only allowed to
 * touch the database when the marker exists, so a fresh checkout can boot and
 * show the installer even before a database, an APP_KEY or a .env file exist.
 */
class InstallationManager
{
    /** Whether the installer is allowed to run at all. */
    public function isEnabled(): bool
    {
        return filter_var(config('yac.installer.enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    /** Whether the installation has been completed (or deliberately skipped). */
    public function isInstalled(): bool
    {
        return is_file($this->markerPath());
    }

    /** True while the application still has to be installed in a browser. */
    public function shouldRunInstaller(): bool
    {
        return $this->isEnabled() && ! $this->isInstalled();
    }

    public function markerPath(): string
    {
        return $this->resolvePath('yac.installer.marker', storage_path('app/installation.json'));
    }

    public function envPath(): string
    {
        return $this->resolvePath('yac.installer.env_path', base_path('.env'));
    }

    /**
     * Details recorded when the installation finished.
     *
     * @return array<string, mixed>|null
     */
    public function state(): ?array
    {
        $path = $this->markerPath();

        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function markInstalled(array $details = []): void
    {
        $path = $this->markerPath();
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException(sprintf('The directory "%s" could not be created.', $directory));
        }

        $payload = array_merge([
            'installed_at' => now()->toIso8601String(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ], $details);

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (@file_put_contents($path, $encoded."\n") === false) {
            throw new \RuntimeException('The installation marker could not be written to storage/app.');
        }
    }

    /** Remove the marker so the installer can be run again. */
    public function forget(): void
    {
        @unlink($this->markerPath());
    }

    /**
     * Requests made before installation still need an APP_KEY for cookies and
     * sessions. Generate one and persist it so it stays stable until the real
     * key is written to .env.
     */
    public function ensureApplicationKey(): void
    {
        if (filled(config('app.key'))) {
            return;
        }

        $path = storage_path('framework/installer.key');
        $key = is_file($path) ? trim((string) @file_get_contents($path)) : '';

        if (! str_starts_with($key, 'base64:')) {
            $key = 'base64:'.base64_encode(random_bytes(32));

            try {
                if (is_writable(dirname($path))) {
                    @file_put_contents($path, $key);
                }
            } catch (Throwable) {
                // Without a writable storage directory every request would use
                // a different key and forms would fail their CSRF check. The
                // requirements screen reports the permission problem.
            }
        }

        config(['app.key' => $key]);
    }

    /**
     * A cached configuration ignores .env, so a freshly written file would have
     * no effect. Remove the cache so the next request picks the values up.
     */
    public function forgetConfigurationCache(): bool
    {
        $path = base_path('bootstrap/cache/config.php');

        if (! is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    private function resolvePath(string $key, string $default): string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? $value : $default;
    }
}
