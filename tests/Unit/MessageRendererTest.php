<?php

namespace Tests\Unit;

use App\Services\Sms\MessageRenderer;
use PHPUnit\Framework\TestCase;

class MessageRendererTest extends TestCase
{
    private MessageRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new MessageRenderer();
    }

    public function test_it_renders_the_supported_placeholders(): void
    {
        $rendered = $this->renderer->render(
            'Hi {{name}}, your number {{phone}} and email {{email}} are on file.',
            [
                'name' => 'Ada',
                'phone' => '2348031234567',
                'email' => 'ada@example.com',
                'custom_fields' => [],
            ],
        );

        $this->assertSame('Hi Ada, your number 2348031234567 and email ada@example.com are on file.', $rendered);
    }

    public function test_it_renders_custom_fields_case_insensitively(): void
    {
        $rendered = $this->renderer->render('Hello {{ custom.department }}', [
            'name' => null,
            'phone' => '2348031234567',
            'email' => null,
            'custom_fields' => ['department' => 'Accounts'],
        ]);

        $this->assertSame('Hello Accounts', $rendered);
    }

    public function test_unknown_placeholders_render_empty_instead_of_leaking_the_token(): void
    {
        $rendered = $this->renderer->render('Hi {{unknown}} and {{name}}', [
            'name' => 'Ada',
            'phone' => '2348031234567',
            'email' => null,
            'custom_fields' => [],
        ]);

        $this->assertSame('Hi  and Ada', $rendered);
    }

    public function test_it_detects_placeholders(): void
    {
        $this->assertTrue($this->renderer->containsPlaceholders('Hi {{name}}'));
        $this->assertTrue($this->renderer->containsPlaceholders('Hi {{ name }}'));
        $this->assertFalse($this->renderer->containsPlaceholders('Hi Ada'));
    }

    public function test_validation_rejects_unknown_placeholders(): void
    {
        $errors = $this->renderer->validate('Hi {{first_name}}', ['department']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('first_name', $errors[0]);
    }

    public function test_validation_accepts_approved_custom_fields(): void
    {
        $this->assertSame([], $this->renderer->validate('Hi {{name}}, ref {{custom.account_number}}', ['account_number']));
    }

    public function test_validation_rejects_malformed_placeholders(): void
    {
        $errors = $this->renderer->validate('Hi {{name}', []);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('malformed', strtolower($errors[0]));
    }

    public function test_validation_rejects_empty_messages(): void
    {
        $this->assertNotEmpty($this->renderer->validate('   ', []));
    }

    public function test_sample_uses_obviously_fake_values(): void
    {
        $sample = $this->renderer->sample('Hi {{name}}', []);

        $this->assertSame('Hi Ada Okafor', $sample);
    }
}
