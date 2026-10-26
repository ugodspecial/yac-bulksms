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
    | Browser installer
    |--------------------------------------------------------------------------
    |
    | When enabled, opening the application before it has been installed
    | shows a browser installer instead of failing on a missing database.
    | The installer writes .env, creates the database and runs the migrations.
    |
    | Set YAC_INSTALLER_ENABLED=false on servers that are configured entirely
    | through environment variables (for example container platforms).
    |
    */
    'installer' => [
        'enabled' => filter_var(env('YAC_INSTALLER_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        // Written once the installer finished (or was deliberately skipped).
        'marker' => env('YAC_INSTALLER_MARKER', storage_path('app/installation.json')),

        // Use the default .env in the application root unless overridden.
        'env_path' => env('YAC_INSTALLER_ENV_PATH', base_path('.env')),
    ],

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
