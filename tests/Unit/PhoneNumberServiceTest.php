<?php

namespace Tests\Unit;

use App\Services\Phone\InvalidPhoneNumberException;
use App\Services\Phone\PhoneNumberService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberServiceTest extends TestCase
{
    private PhoneNumberService $phones;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phones = new PhoneNumberService();
    }

    #[DataProvider('nigerianNumbers')]
    public function test_it_normalises_nigerian_numbers_to_e164_digits(string $input): void
    {
        $this->assertSame('2348031234567', $this->phones->normalize($input, 'NG'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nigerianNumbers(): array
    {
        return [
            'local format' => ['08031234567'],
            'local with spaces' => ['0803 123 4567'],
            'international with plus' => ['+2348031234567'],
            'international with spaces' => ['+234 803 123 4567'],
            'international with dashes' => ['+234-803-123-4567'],
            'international with zero zero prefix' => ['002348031234567'],
            'digits only international' => ['2348031234567'],
            'parentheses' => ['(0803) 123-4567'],
        ];
    }

    public function test_invalid_numbers_throw(): void
    {
        $this->expectException(InvalidPhoneNumberException::class);

        $this->phones->normalize('12345', 'NG');
    }

    public function test_try_normalize_returns_null_for_invalid_numbers(): void
    {
        $this->assertNull($this->phones->tryNormalize('not a number', 'NG'));
        $this->assertSame('2348031234567', $this->phones->tryNormalize('08031234567', 'NG'));
    }

    public function test_it_formats_numbers_for_display(): void
    {
        $display = $this->phones->toInternationalDisplay('2348031234567');

        $this->assertStringStartsWith('+234', $display);
        $this->assertStringContainsString('803', $display);
    }

    public function test_it_reports_the_region(): void
    {
        $this->assertSame('NG', $this->phones->regionFor('2348031234567'));
    }
}
