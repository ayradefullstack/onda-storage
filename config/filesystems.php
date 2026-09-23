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
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
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

        // ONDA vault disks. All four are rooted OUTSIDE the project directory,
        // on one shared partition (rename() during upload complete() must be
        // instant), 0700 dirs / 0600 files, and 'throw' so a failed write
        // surfaces as an exception instead of a silently-false return value.
        // Never `Storage::get()`/`storage:link` these — see CLAUDE.md.
        'vault' => [
            'driver' => 'local',
            'root' => env('VAULT_DISK_ROOT'),
            'visibility' => 'private',
            'permissions' => [
                'file' => ['private' => 0600],
                'dir' => ['private' => 0700],
            ],
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'incoming' => [
            'driver' => 'local',
            'root' => env('INCOMING_DISK_ROOT'),
            'visibility' => 'private',
            'permissions' => [
                'file' => ['private' => 0600],
                'dir' => ['private' => 0700],
            ],
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'work' => [
            'driver' => 'local',
            'root' => env('WORK_DISK_ROOT'),
            'visibility' => 'private',
            'permissions' => [
                'file' => ['private' => 0600],
                'dir' => ['private' => 0700],
            ],
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'variants' => [
            'driver' => 'local',
            'root' => env('VARIANTS_DISK_ROOT'),
            'visibility' => 'private',
            'permissions' => [
                'file' => ['private' => 0600],
                'dir' => ['private' => 0700],
            ],
            'serve' => false,
            'throw' => true,
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
