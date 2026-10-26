<?php

namespace App\Services\Sms;

use App\Enums\SmsEncoding;

/**
 * Estimates SMS segments using the GSM 03.38 / UCS-2 rules.
 *
 * - GSM-7 single part: 160 septets, multipart: 153 per part.
 * - GSM-7 extension characters (^{}\[~]|€) cost two septets each.
 * - UCS-2 single part: 70 UTF-16 code units, multipart: 67 per part.
 */
class SegmentCalculator
{
    /** GSM 03.38 basic character set (one septet each). */
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** GSM 03.38 extension table characters (two septets each). */
    private const GSM_EXTENSION = "^{}\\[~]|€";

    public const GSM_SINGLE = 160;
    public const GSM_MULTIPART = 153;
    public const UCS2_SINGLE = 70;
    public const UCS2_MULTIPART = 67;

    public function calculate(string $message): SmsSegments
    {
        $length = mb_strlen($message, 'UTF-8');

        if ($length === 0) {
            return new SmsSegments(SmsEncoding::Gsm7, 0, 0, 0, false);
        }

        [$encoding, $units] = $this->measure($message);

        if ($encoding === SmsEncoding::Gsm7) {
            $segments = $units <= self::GSM_SINGLE
                ? 1
                : (int) ceil($units / self::GSM_MULTIPART);
        } else {
            $segments = $units <= self::UCS2_SINGLE
                ? 1
                : (int) ceil($units / self::UCS2_MULTIPART);
        }

        return new SmsSegments(
            encoding: $encoding,
            length: $length,
            segments: $segments,
            units: $units,
            isMultipart: $segments > 1,
        );
    }

    public function encodingFor(string $message): SmsEncoding
    {
        return $this->measure($message)[0];
    }

    /**
     * @return array{0: SmsEncoding, 1: int} encoding and billable units
     */
    private function measure(string $message): array
    {
        $basic = $this->characterSet(self::GSM_BASIC);
        $extension = $this->characterSet(self::GSM_EXTENSION);

        $units = 0;
        $isGsm = true;

        foreach (mb_str_split($message, 1, 'UTF-8') as $char) {
            if (isset($basic[$char])) {
                $units += 1;

                continue;
            }

            if (isset($extension[$char])) {
                $units += 2;

                continue;
            }

            $isGsm = false;
            break;
        }

        if ($isGsm) {
            return [SmsEncoding::Gsm7, $units];
        }

        // UCS-2 counts UTF-16 code units: BMP characters are 1, emoji are 2.
        $utf16 = mb_convert_encoding($message, 'UTF-16BE', 'UTF-8');

        if (is_string($utf16) && $utf16 !== '') {
            return [SmsEncoding::Ucs2, intdiv(strlen($utf16), 2)];
        }

        return [SmsEncoding::Ucs2, mb_strlen($message, 'UTF-8')];
    }

    /**
     * @return array<string, true>
     */
    private function characterSet(string $characters): array
    {
        $set = [];

        foreach (mb_str_split($characters, 1, 'UTF-8') as $char) {
            $set[$char] = true;
        }

        return $set;
    }
}
