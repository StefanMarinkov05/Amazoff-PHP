<?php

declare(strict_types=1);

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
    | Media Disk — where admin uploads land
    |--------------------------------------------------------------------------
    |
    | Uploaded media (product images, article covers) is written to whichever
    | disk this names, while seeded demo content stays on `public`, baked into
    | the container image. `ProductImage::disk()` picks between the two by path
    | prefix; see ADR-0025.
    |
    | This indirection is the whole S3 switch: set MEDIA_DISK=s3 and every
    | upload path follows, because both disks expose the same Storage contract.
    | It is also the rollback lever — MEDIA_DISK=public restores the previous
    | single-disk behaviour with no code change.
    |
    */

    'media_disk' => env('MEDIA_DISK', 'media'),

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

        /*
         * Seeded demo content only — committed to the repository under
         * `storage/app/public/demo/` and therefore baked into the container
         * image, which is what makes the demo catalogue render after any
         * redeploy. Admin uploads do NOT come here; see the `media` disk.
         */
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Admin uploads. Deliberately rooted OUTSIDE `storage/app/public`:
         * on Railway this path is a mounted volume, and mounting it over the
         * seed directory would shadow the committed demo images with an empty
         * volume. Keeping the trees disjoint makes that impossible rather
         * than merely avoided. ADR-0025.
         */
        'media' => [
            'driver' => 'local',
            'root' => storage_path('app/media'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/media',
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
        public_path('media') => storage_path('app/media'),
    ],

];
