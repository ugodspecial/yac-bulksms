<?php

namespace App\Services\Install;

/**
 * The server checks shown on the first installer screen. Nothing here touches
 * the database: the installer has to work before one exists.
 */
class RequirementsChecker
{
    private const REQUIRED_EXTENSIONS = [
        'bcmath',
        'ctype',
        'curl',
        'dom',
        'fileinfo',
        'json',
        'mbstring',
        'openssl',
        'pdo',
        'tokenizer',
        'xml',
    ];

    private const RECOMMENDED_EXTENSIONS = [
        'intl',
    ];

    /**
     * @return array<int, array{label: string, detail: string, ok: bool, required: bool}>
     */
    public function checks(): array
    {
        return array_merge(
            $this->runtimeChecks(),
            $this->extensionChecks(),
            $this->databaseDriverChecks(),
            $this->permissionChecks(),
            $this->assetChecks(),
        );
    }

    public function passes(): bool
    {
        foreach ($this->checks() as $check) {
            if ($check['required'] && ! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function failures(): array
    {
        return array_values(array_map(
            fn (array $check) => $check['label'],
            array_filter($this->checks(), fn (array $check) => $check['required'] && ! $check['ok']),
        ));
    }

    /**
     * @return array<int, array{label: string, detail: string, ok: bool, required: bool}>
     */
    private function runtimeChecks(): array
    {
        return [
            $this->check(
                'PHP '.$this->minimumPhpVersion().' or newer',
                'Running PHP '.PHP_VERSION.'.',
                version_compare(PHP_VERSION, $this->minimumPhpVersion(), '>='),
            ),
        ];
    }

    /**
     * @return array<int, array{label: string, detail: string, ok: bool, required: bool}>
     */
    private function extensionChecks(): array
    {
        $checks = [];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $checks[] = $this->check(
                'PHP extension: '.$extension,
                extension_loaded($extension) ? 'Loaded.' : 'Missing. Ask your host to enable it.',
                extension_loaded($extension),
            );
        }

        foreach (self::RECOMMENDED_EXTENSIONS as $extension) {
            $checks[] = $this->check(
                'PHP extension: '.$extension,
                extension_loaded($extension) ? 'Loaded.' : 'Recommended for phone number formatting; not required.',
                extension_loaded($extension),
                required: false,
            );
        }

        return $checks;
    }

    /**
     * @return array<int, array{label: string, detail: string, ok: bool, required: bool}>
     */
    private function databaseDriverChecks(): array
    {
        $available = array_values(array_filter([
            extension_loaded('pdo_mysql') ? 'MySQL (pdo_mysql)' : null,
            extension_loaded('pdo_sqlite') ? 'SQLite (pdo_sqlite)' : null,
        ]));

        return [
            $this->check(
                'A supported database driver',
                $available !== [] ? 'Available: '.implode(' or ', $available).'.' : 'Enable pdo_mysql or pdo_sqlite.',
                $available !== [],
            ),
        ];
    }

    /**
     * @return array<int, array{label: string, detail: string, ok: bool, required: bool}>
     */
    private function permissionChecks(): array
    {
        $paths = [
            'Application root (writes .env)' => base_path(),
            'storage' => storage_path(),
            'storage/app' => storage_path('app'),
            'storage/framework/cache' => storage_path('framework/cache'),
            'storage/framework/sessions' => storage_path('framework/sessions'),
            'storage/framework/views' => storage_path('framework/views'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        $checks = [];

        foreach ($paths as $label => $path) {
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);

            $checks[] = $this->check(
                'Writable: '.$label,
                match (true) {
                    ! $exists => 'The directory does not exist.',
                    $writable => $path,
                    default => 'Not writable by the web server user.',
                },
                $writable,
            );
        }

        return $checks;
    }

    /**
     * @return array<int, array{label: string, detail: string, ok: bool, required: bool}>
     */
    private function assetChecks(): array
    {
        $manifest = public_path('build/manifest.json');

        return [
            $this->check(
                'Frontend assets built',
                is_file($manifest)
                    ? 'public/build/manifest.json found.'
                    : 'Run "npm ci && npm run build" (or deploy public/build) before installing.',
                is_file($manifest),
            ),
        ];
    }

    /**
     * @return array{label: string, detail: string, ok: bool, required: bool}
     */
    private function check(string $label, string $detail, bool $ok, bool $required = true): array
    {
        return [
            'label' => $label,
            'detail' => $detail,
            'ok' => $ok,
            'required' => $required,
        ];
    }

    private function minimumPhpVersion(): string
    {
        return '8.2.0';
    }
}
