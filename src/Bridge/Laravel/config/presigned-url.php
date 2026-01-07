<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Secret Key
    |--------------------------------------------------------------------------
    |
    | The secret key used to sign presigned URLs. This should be a secure
    | random string. You can generate one using: php artisan key:generate
    |
    */
    'secret' => env('PRESIGNED_STORAGE_SECRET', env('APP_KEY')),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL for serving presigned files. This should be the URL
    | where your serve route is accessible.
    |
    */
    'base_url' => env('PRESIGNED_STORAGE_URL', env('APP_URL') . '/storage/serve'),

    /*
    |--------------------------------------------------------------------------
    | Signature Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the signature algorithm and parameters.
    |
    */
    'signature' => [
        'algorithm' => 'sha256',
        'length' => 16,
        'expires_param' => 'X-Expires',
        'signature_param' => 'X-Signature',
    ],

    /*
    |--------------------------------------------------------------------------
    | Serving Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how files are served, including TTL, caching, and compression.
    |
    */
    'serving' => [
        'default_ttl' => 3600,
        'max_ttl' => 86400,
        'cache_control' => 'private, max-age=3600, must-revalidate',
        'content_disposition' => 'inline',
        'compression' => [
            'enabled' => true,
            'min_size' => 1024,
            'level' => 6,
            'types' => [
                'text/plain',
                'text/html',
                'text/css',
                'text/xml',
                'text/javascript',
                'application/javascript',
                'application/json',
                'application/xml',
                'image/svg+xml',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Configure security settings like allowed/blocked extensions and CORS.
    |
    */
    'security' => [
        'allowed_extensions' => [],
        'blocked_extensions' => ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'exe', 'sh', 'bat', 'cmd'],
        'max_file_size' => 0,
        'allowed_origins' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Buckets
    |--------------------------------------------------------------------------
    |
    | Define your storage buckets. Each bucket can use a different adapter.
    |
    | Supported adapters:
    | - local: Local filesystem storage
    | - flysystem: League Flysystem adapter
    | - s3: AWS S3 native presigned URLs
    |
    */
    'buckets' => [
        // 'documents' => [
        //     'adapter' => 'local',
        //     'path' => storage_path('app/documents'),
        // ],

        // 's3-files' => [
        //     'adapter' => 's3',
        //     'key' => env('AWS_ACCESS_KEY_ID'),
        //     'secret' => env('AWS_SECRET_ACCESS_KEY'),
        //     'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        //     'bucket' => env('AWS_BUCKET'),
        //     'endpoint' => env('AWS_ENDPOINT'),
        // ],
    ],
];
