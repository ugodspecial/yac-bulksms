<?php

namespace App\Services\Install;

use App\Exceptions\InstallException;

/**
 * Writes values into a .env file, preserving any keys and comments that the
 * installer does not manage. A .env.backup copy is kept before every write.
 */
class EnvFileWriter
{
    /**
     * @param  array<string, string|null>  $values
     */
    public function write(string $path, array $values): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new InstallException(sprintf('The directory "%s" does not exist and could not be created.', $directory));
        }

        $existing = is_file($path) ? @file_get_contents($path) : null;

        if ($existing === false) {
            throw new InstallException('The existing .env file could not be read.');
        }

        if ($existing !== null) {
            @copy($path, $path.'.backup');
        }

        $contents = $this->render($existing, $values);

        if (@file_put_contents($path, $contents) === false) {
            throw new InstallException('The .env file could not be written. Give the web server user write access to the application directory.');
        }

        @chmod($path, 0640);
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public function render(?string $existing, array $values): string
    {
        $lines = $existing === null || trim($existing) === ''
            ? []
            : preg_split('/\r\n|\r|\n/', $existing);

        $lines = is_array($lines) ? $lines : [];
        $rendered = [];
        $replaced = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*([A-Za-z0-9_]+)\s*=/', $line, $matches) === 1 && array_key_exists($matches[1], $values)) {
                $rendered[] = $matches[1].'='.$this->formatValue($values[$matches[1]]);
                $replaced[$matches[1]] = true;

                continue;
            }

            $rendered[] = $line;
        }

        $appended = [];

        foreach ($values as $key => $value) {
            if (! isset($replaced[$key])) {
                $appended[] = $key.'='.$this->formatValue($value);
            }
        }

        if ($appended !== []) {
            if ($rendered !== [] && trim((string) end($rendered)) !== '') {
                $rendered[] = '';
            }

            $rendered = array_merge($rendered, $appended);
        }

        return rtrim(implode("\n", $rendered), "\n")."\n";
    }

    public function formatValue(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // Plain values (paths, numbers, hosts, base64 keys) stay unquoted so
        // the file remains easy to read.
        if (preg_match('#^[A-Za-z0-9_\-./:@,+*=%]+$#', $value) === 1) {
            return $value;
        }

        // Single quotes are literal in phpdotenv: no "$" interpolation.
        if (! str_contains($value, "'") && ! str_contains($value, '\\')) {
            return "'".$value."'";
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
