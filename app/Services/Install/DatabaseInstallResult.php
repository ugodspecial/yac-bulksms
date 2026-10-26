<?php

namespace App\Services\Install;

/** Outcome of the "create the database" step. */
final readonly class DatabaseInstallResult
{
    public function __construct(
        public bool $ok,
        public string $message,
        public string $driver = 'sqlite',
        public ?string $database = null,
        public bool $created = false,
        public ?string $serverVersion = null,
    ) {}

    public static function success(
        string $message,
        string $driver,
        string $database,
        bool $created,
        ?string $serverVersion = null,
    ): self {
        return new self(true, $message, $driver, $database, $created, $serverVersion);
    }

    public static function failure(string $message, string $driver = 'sqlite', ?string $database = null): self
    {
        return new self(false, $message, $driver, $database);
    }
}
