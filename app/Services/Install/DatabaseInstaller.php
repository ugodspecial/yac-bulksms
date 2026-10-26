<?php

namespace App\Services\Install;

use Illuminate\Support\Str;
use PDO;
use PDOException;

/**
 * Creates (and verifies) the database before the migrations run.
 *
 * SQLite: the database is a file on disk, so creating it means creating the
 * file. MySQL: the installer connects to the server without selecting a
 * schema and issues CREATE DATABASE when the schema is missing.
 */
class DatabaseInstaller
{
    /**
     * @param  array{driver?: string, database?: string|null, host?: string|null, port?: int|string|null, username?: string|null, password?: string|null}  $settings
     */
    public function install(array $settings): DatabaseInstallResult
    {
        $driver = is_string($settings['driver'] ?? null) && $settings['driver'] !== ''
            ? $settings['driver']
            : 'sqlite';

        return match ($driver) {
            'sqlite' => $this->installSqlite($settings),
            'mysql' => $this->installMysql($settings),
            default => DatabaseInstallResult::failure(sprintf('The "%s" database driver is not supported by the installer. Choose MySQL or SQLite.', $driver), $driver),
        };
    }

    /**
     * @param  array{database?: string|null}  $settings
     */
    private function installSqlite(array $settings): DatabaseInstallResult
    {
        $path = is_string($settings['database'] ?? null) && trim($settings['database']) !== ''
            ? $this->absolutePath(trim($settings['database']))
            : database_path('database.sqlite');

        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return DatabaseInstallResult::failure(sprintf('The directory "%s" does not exist and could not be created.', $directory), 'sqlite', $path);
        }

        if (! is_writable($directory)) {
            return DatabaseInstallResult::failure(sprintf('The directory "%s" is not writable by the web server user.', $directory), 'sqlite', $path);
        }

        $created = ! is_file($path);

        if ($created && @touch($path) === false) {
            return DatabaseInstallResult::failure(sprintf('The SQLite database file "%s" could not be created.', $path), 'sqlite', $path);
        }

        try {
            $pdo = new PDO('sqlite:'.$path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $version = $pdo->query('select sqlite_version()')->fetchColumn();
        } catch (PDOException $e) {
            return DatabaseInstallResult::failure('The SQLite database could not be opened: '.Str::limit($e->getMessage(), 300), 'sqlite', $path);
        }

        return DatabaseInstallResult::success(
            $created
                ? sprintf('Created the SQLite database at %s.', $path)
                : sprintf('Using the existing SQLite database at %s.', $path),
            'sqlite',
            $path,
            $created,
            is_string($version) ? 'SQLite '.$version : null,
        );
    }

    /** Resolve a relative path (for example database/database.sqlite) against the application root. */
    public function absolutePath(string $path): string
    {
        if ($path === ':memory:' || str_starts_with($path, 'file:')) {
            return $path;
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @param  array{host?: string|null, port?: int|string|null, database?: string|null, username?: string|null, password?: string|null}  $settings
     */
    private function installMysql(array $settings): DatabaseInstallResult
    {
        $host = trim((string) ($settings['host'] ?? '')) ?: '127.0.0.1';
        $port = (int) ($settings['port'] ?? 3306);
        $database = trim((string) ($settings['database'] ?? ''));
        $username = (string) ($settings['username'] ?? '');
        $password = (string) ($settings['password'] ?? '');

        if ($port < 1 || $port > 65535) {
            $port = 3306;
        }

        // Identifiers cannot be bound, so the name is validated strictly.
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            return DatabaseInstallResult::failure('The database name may only contain letters, numbers and underscores (maximum 64 characters).', 'mysql', $database);
        }

        $baseDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 8,
        ];

        $created = false;

        try {
            try {
                $pdo = new PDO($baseDsn.';dbname='.$database, $username, $password, $options);
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1049) {
                    throw $e;
                }

                // Unknown database: create it using a connection without a schema.
                $server = new PDO($baseDsn, $username, $password, $options);
                $server->exec(sprintf(
                    'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    $database
                ));

                $created = true;
                $pdo = new PDO($baseDsn.';dbname='.$database, $username, $password, $options);
            }

            $version = $pdo->query('select version()')->fetchColumn();
            $pdo->query('select 1');
        } catch (PDOException $e) {
            return DatabaseInstallResult::failure($this->friendlyMysqlError($e, $host, $port, $database), 'mysql', $database);
        }

        return DatabaseInstallResult::success(
            $created
                ? sprintf('Created the MySQL database "%s".', $database)
                : sprintf('Connected to the MySQL database "%s".', $database),
            'mysql',
            $database,
            $created,
            is_string($version) ? 'MySQL '.$version : null,
        );
    }

    private function friendlyMysqlError(PDOException $e, string $host, int $port, string $database): string
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $raw = $e->getMessage();

        return match (true) {
            $code === 1045 => 'The database username or password was rejected by the server.',
            $code === 1044 || $code === 1142 || $code === 1227 => sprintf(
                'Connected to the server, but the database user may not create or use "%s". Create the database manually (or grant the privilege) and try again.',
                $database,
            ),
            $code === 2002, $code === 2005, $code === 2006,
            str_contains($raw, 'Connection refused'),
            str_contains($raw, 'getaddrinfo'),
            str_contains($raw, 'No such host is known') => sprintf(
                'Could not reach the database server at %s:%d. Check the host, the port and that the database server is running.',
                $host,
                $port,
            ),
            default => 'The MySQL database could not be prepared: '.Str::limit($raw, 300),
        };
    }
}
