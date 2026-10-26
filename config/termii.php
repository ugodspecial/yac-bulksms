<?php

declare(strict_types=1);

/*
 * Configuration published from zeevx/lara-termii. Values are read from the
 * environment only - the API key must never be stored in the database or
 * exposed to the browser.
 */

return [

    'api_key' => env('TERMII_API_KEY'),

    'base_url' => env('TERMII_BASE_URL', 'https://v4.api.termii.com'),

    'sender_id' => env('TERMII_SENDER_ID'),

    'channel' => env('TERMII_CHANNEL', 'generic'),

    'timeout' => (int) env('TERMII_TIMEOUT', 30),

    'throw' => (bool) env('TERMII_THROW', false),

    /*
     * Delivery report / inbound webhook. Not part of the lara-termii package:
     * if the secret is empty the webhook route rejects every request.
     */
    'webhook' => [
        'enabled' => (bool) env('TERMII_WEBHOOK_ENABLED', false),
        'secret' => env('TERMII_WEBHOOK_SECRET'),
    ],

];
