<?php

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        /*
         * Private application disk. Import uploads live here; they are never
         * directly web-accessible.
         */
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        /*
         * Public disk, used only for the optional application logo. Run
         * "php artisan storage:link" after deploying.
         */
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
