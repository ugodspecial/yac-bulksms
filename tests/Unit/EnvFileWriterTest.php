<?php

namespace Tests\Unit;

use App\Services\Install\EnvFileWriter;
use PHPUnit\Framework\TestCase;

class EnvFileWriterTest extends TestCase
{
    public function test_it_replaces_managed_keys_and_keeps_everything_else(): void
    {
        $existing = implode("\n", [
            '# Managed by hand',
            'APP_NAME=Old',
            'DB_DATABASE=old_database',
            '',
            'CUSTOM_KEY=custom value',
            'DB_PASSWORD=secret with spaces',
            '',
        ]);

        $rendered = (new EnvFileWriter())->render($existing, [
            'APP_NAME' => 'New name',
            'DB_DATABASE' => 'new_database',
            'DB_PASSWORD' => 'n3w$ecret',
            'SESSION_DRIVER' => 'database',
        ]);

        $this->assertStringContainsString('# Managed by hand', $rendered);
        // Untouched lines are preserved verbatim.
        $this->assertStringContainsString('CUSTOM_KEY=custom value', $rendered);
        // Spaces force quoting; single quotes keep "$" literal for phpdotenv.
        $this->assertStringContainsString("APP_NAME='New name'", $rendered);
        $this->assertStringContainsString("DB_PASSWORD='n3w\$ecret'", $rendered);
        $this->assertStringContainsString('DB_DATABASE=new_database', $rendered);
        $this->assertStringContainsString('SESSION_DRIVER=database', $rendered);

        // Replaced in place, not duplicated.
        $this->assertSame(1, substr_count($rendered, 'APP_NAME='));
        $this->assertSame(1, substr_count($rendered, 'DB_DATABASE='));
        $this->assertStringNotContainsString('old_database', $rendered);
    }

    public function test_it_escapes_values_that_contain_single_quotes(): void
    {
        $rendered = (new EnvFileWriter())->render(null, [
            'APP_NAME' => "YAC's SMS",
        ]);

        $this->assertStringContainsString('APP_NAME="YAC\'s SMS"', $rendered);
    }

    public function test_it_escapes_double_quotes_and_backslashes(): void
    {
        $rendered = (new EnvFileWriter())->render(null, [
            'WEIRD' => 'C:\\path\\"quoted"',
        ]);

        $this->assertStringContainsString('WEIRD="C:\\\\path\\\\\\"quoted\\""', $rendered);
    }

    public function test_empty_values_are_written_without_quotes(): void
    {
        $rendered = (new EnvFileWriter())->render("APP_KEY=old\n", [
            'APP_KEY' => null,
            'TERMII_API_KEY' => '',
        ]);

        $this->assertStringContainsString("APP_KEY=\n", $rendered);
        $this->assertStringContainsString("TERMII_API_KEY=\n", $rendered);
        $this->assertStringNotContainsString('APP_KEY=""', $rendered);
    }

    public function test_a_new_file_ends_with_a_single_newline(): void
    {
        $rendered = (new EnvFileWriter())->render(null, ['APP_NAME' => 'YAC']);

        $this->assertSame("APP_NAME=YAC\n", $rendered);
    }
}
