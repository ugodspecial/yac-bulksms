<?php

use App\Services\Settings\SettingsService;

if (! function_exists('settings')) {
    /**
     * Typed access to the non-secret application settings.
     */
    function settings(): SettingsService
    {
        return app(SettingsService::class);
    }
}

if (! function_exists('app_name')) {
    function app_name(): string
    {
        return settings()->appName();
    }
}

if (! function_exists('format_phone')) {
    /**
     * Display a digits-only international number for humans without letting
     * the formatting library fail the request.
     */
    function format_phone(?string $digits): string
    {
        if (blank($digits)) {
            return '—';
        }

        try {
            return app(\App\Services\Phone\PhoneNumberService::class)->toInternationalDisplay($digits);
        } catch (\Throwable) {
            return '+'.$digits;
        }
    }
}
