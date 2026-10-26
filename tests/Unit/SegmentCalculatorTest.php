<?php

namespace Tests\Unit;

use App\Enums\SmsEncoding;
use App\Services\Sms\SegmentCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SegmentCalculatorTest extends TestCase
{
    private SegmentCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new SegmentCalculator();
    }

    public function test_a_short_message_is_one_gsm_segment(): void
    {
        $result = $this->calculator->calculate('Hello Ada, your order is ready.');

        $this->assertSame(SmsEncoding::Gsm7, $result->encoding);
        $this->assertSame(1, $result->segments);
        $this->assertFalse($result->isMultipart);
    }

    public function test_exactly_160_gsm_characters_fit_in_one_segment(): void
    {
        $result = $this->calculator->calculate(str_repeat('a', 160));

        $this->assertSame(SmsEncoding::Gsm7, $result->encoding);
        $this->assertSame(160, $result->units);
        $this->assertSame(1, $result->segments);
    }

    public function test_161_gsm_characters_need_two_segments_of_153(): void
    {
        $result = $this->calculator->calculate(str_repeat('a', 161));

        $this->assertSame(2, $result->segments);
        $this->assertTrue($result->isMultipart);
    }

    public function test_306_gsm_characters_fit_in_exactly_two_segments(): void
    {
        $this->assertSame(2, $this->calculator->calculate(str_repeat('a', 306))->segments);
        $this->assertSame(3, $this->calculator->calculate(str_repeat('a', 307))->segments);
    }

    public function test_gsm_extension_characters_cost_two_septets(): void
    {
        // 80 braces = 160 septets: still a single segment...
        $this->assertSame(1, $this->calculator->calculate(str_repeat('{', 80))->segments);

        // ...but 81 braces are 162 septets and spill into a second segment.
        $this->assertSame(2, $this->calculator->calculate(str_repeat('{', 81))->segments);
    }

    public function test_gsm_basic_accented_characters_do_not_force_unicode(): void
    {
        $result = $this->calculator->calculate('Café à la carte');

        $this->assertSame(SmsEncoding::Gsm7, $result->encoding);
    }

    public function test_cyrillic_characters_force_ucs2_and_use_70_character_segments(): void
    {
        $result = $this->calculator->calculate(str_repeat('П', 70));

        $this->assertSame(SmsEncoding::Ucs2, $result->encoding);
        $this->assertSame(1, $result->segments);

        $this->assertSame(2, $this->calculator->calculate(str_repeat('П', 71))->segments);
    }

    public function test_ucs2_multipart_segments_are_67_characters(): void
    {
        $this->assertSame(2, $this->calculator->calculate(str_repeat('П', 134))->segments);
        $this->assertSame(3, $this->calculator->calculate(str_repeat('П', 135))->segments);
    }

    public function test_emoji_use_two_utf16_code_units(): void
    {
        // 35 emoji = 70 UTF-16 code units (one UCS-2 segment), 36 = 72 (two).
        $this->assertSame(1, $this->calculator->calculate(str_repeat('😀', 35))->segments);
        $this->assertSame(2, $this->calculator->calculate(str_repeat('😀', 36))->segments);
    }

    public function test_empty_messages_have_no_segments(): void
    {
        $this->assertSame(0, $this->calculator->calculate('')->segments);
    }

    #[DataProvider('encodingProvider')]
    public function test_encoding_detection(string $message, SmsEncoding $expected): void
    {
        $this->assertSame($expected, $this->calculator->calculate($message)->encoding);
    }

    /**
     * @return array<string, array{0: string, 1: SmsEncoding}>
     */
    public static function encodingProvider(): array
    {
        return [
            'plain ascii' => ['Hello', SmsEncoding::Gsm7],
            'gsm special characters' => ['@ £ $ ¥ è é ù ì ò Ç Ø å Δ _', SmsEncoding::Gsm7],
            'extension characters' => ['{}[]~|^\\', SmsEncoding::Gsm7],
            'euro sign' => ['€5', SmsEncoding::Gsm7],
            'cyrillic' => ['Привет', SmsEncoding::Ucs2],
            'chinese' => ['你好', SmsEncoding::Ucs2],
            'emoji' => ['😀', SmsEncoding::Ucs2],
            'typographic quotes' => ['“quoted”', SmsEncoding::Ucs2],
        ];
    }
}
