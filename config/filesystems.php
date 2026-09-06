<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Logical Application Disks
    |--------------------------------------------------------------------------
    |
    | Application-owned files resolve through logical disk names so a
    | deployment can change its storage provider without changing controllers.
    |
    */

    'message_attachments_disk' => env('MESSAGE_ATTACHMENTS_DISK', 'message_attachments'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'ar_quarantine' => [
            'driver' => 'local',
            'root' => storage_path('app/private/ar/quarantine'),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        'ar_published' => [
            'driver' => 'local',
            'root' => storage_path('app/public/ar'),
            'url' => rtrim(env('AR_ASSET_BASE_URL', env('APP_URL', 'https://localhost')), '/'),
            'visibility' => 'public',
            'throw' => true,
            'report' => false,
        ],

        'message_attachments' => [
            'driver' => env('MESSAGE_ATTACHMENTS_DRIVER', 'local'),
            'root' => env('MESSAGE_ATTACHMENTS_ROOT', storage_path('app/private/message-attachments')),
            'key' => env('MESSAGE_ATTACHMENTS_KEY', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('MESSAGE_ATTACHMENTS_SECRET', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('MESSAGE_ATTACHMENTS_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('MESSAGE_ATTACHMENTS_BUCKET', env('AWS_BUCKET')),
            'url' => env('MESSAGE_ATTACHMENTS_URL', env('AWS_URL')),
            'endpoint' => env('MESSAGE_ATTACHMENTS_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env(
                'MESSAGE_ATTACHMENTS_USE_PATH_STYLE_ENDPOINT',
                env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            ),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
