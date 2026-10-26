<?php

namespace App\Services\Phone;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Normalises phone numbers to the digits-only international format Termii
 * expects (for example 2348031234567) and validates them with libphonenumber.
 */
class PhoneNumberService
{
    public function __construct(
        private readonly ?PhoneNumberUtil $util = null,
    ) {}

    /**
     * @throws InvalidPhoneNumberException
     */
    public function normalize(string $raw, ?string $defaultRegion = 'NG'): string
    {
        $candidate = $this->clean($raw);

        if ($candidate === '') {
            throw InvalidPhoneNumberException::forNumber($raw, 'The number is empty.');
        }

        $regions = array_values(array_filter([
            $defaultRegion !== null ? strtoupper($defaultRegion) : null,
            'NG',
        ]));

        foreach ($regions as $region) {
            $number = $this->parse($candidate, $region);

            if ($number !== null) {
                return $number;
            }
        }

        // Last resort: treat the input as an already international number.
        $number = $this->parse('+'.ltrim($candidate, '+'), null);

        if ($number !== null) {
            return $number;
        }

        throw InvalidPhoneNumberException::forNumber($raw);
    }

    public function tryNormalize(string $raw, ?string $defaultRegion = 'NG'): ?string
    {
        try {
            return $this->normalize($raw, $defaultRegion);
        } catch (InvalidPhoneNumberException) {
            return null;
        }
    }

    /** Digits-only number, e.g. 2348031234567. */
    public function toE164(string $digits): string
    {
        return '+'.ltrim($digits, '+');
    }

    public function toInternationalDisplay(string $digits): string
    {
        $parsed = $this->parse('+'.ltrim($digits, '+'), null);

        if ($parsed === null) {
            return $this->toE164($digits);
        }

        return $this->util()->format($parsed, PhoneNumberFormat::INTERNATIONAL);
    }

    public function isValid(string $digits): bool
    {
        return $this->parse('+'.ltrim($digits, '+'), null) !== null;
    }

    public function regionFor(string $digits): ?string
    {
        $parsed = $this->parse('+'.ltrim($digits, '+'), null);

        if ($parsed === null) {
            return null;
        }

        return $this->util()->getRegionCodeForNumber($parsed) ?: null;
    }

    /**
     * Strip decoration but keep a leading plus sign (and convert 00 prefixes).
     */
    public function clean(string $raw): string
    {
        $value = trim($raw);
        $value = str_replace(["\u{00A0}", '(', ')', '-', '.', ' ', "\t"], '', $value);

        if (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        }

        // Keep digits and a single leading plus.
        $value = preg_replace('/[^\d+]/', '', $value) ?? '';

        if (str_starts_with($value, '+')) {
            $value = '+'.preg_replace('/\D/', '', substr($value, 1));
        } else {
            $value = preg_replace('/\D/', '', $value) ?? '';
        }

        return $value;
    }

    private function parse(string $candidate, ?string $region): ?string
    {
        try {
            $parsed = $this->util()->parse($candidate, $region);
        } catch (NumberParseException) {
            return null;
        }

        if (! $this->util()->isValidNumber($parsed)) {
            return null;
        }

        return ltrim($this->util()->format($parsed, PhoneNumberFormat::E164), '+');
    }

    private function util(): PhoneNumberUtil
    {
        return $this->util ?? PhoneNumberUtil::getInstance();
    }
}
