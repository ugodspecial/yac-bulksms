<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stale recipient claims
    |--------------------------------------------------------------------------
    |
    | A recipient that has been "processing" for longer than this many minutes
    | is treated as an interrupted job. It is flagged for reconciliation and
    | is never resent automatically.
    |
    */
    'stale_claim_minutes' => (int) env('YAC_STALE_CLAIM_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | First administrator (used by database/seeders/AdminUserSeeder.php)
    |--------------------------------------------------------------------------
    |
    | Read through config so the seeder keeps working when the configuration
    | is cached with "php artisan config:cache".
    |
    */
    'admin' => [
        'name' => env('ADMIN_NAME', 'YAC Administrator'),
        'email' => env('ADMIN_EMAIL', 'admin@yac-bulksms.test'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
