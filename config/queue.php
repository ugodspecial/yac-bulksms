<?php

return [

    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', env('QUEUE_NAME', 'default')),
            'retry_after' => (int) env('YAC_QUEUE_RETRY_AFTER', 120),
            'after_commit' => false,
        ],

    ],

    /*
     * Failed jobs use the framework's database-uuids provider by default.
     */
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => env('DB_FAILED_JOBS_TABLE', 'failed_jobs'),
    ],

];
