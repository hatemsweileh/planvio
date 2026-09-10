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
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Three disks, and deliberately no fourth.
    |
    | Laravel's skeleton ships an `s3` disk here. Planvio removes it rather than
    | leaving it configured, because nothing in the application ever selected it
    | and the package that would make it work — league/flysystem-aws-s3-v3 — is
    | not a dependency and cannot become one on the hosting Planvio targets:
    | production runs with no Composer, and the AWS SDK would add tens of
    | megabytes to a release ZIP that has to be uploaded over cPanel's file
    | manager. A disk that is present in the config, absent in fact, and named in
    | `AWS_*` variables an administrator can fill in is worse than no disk at all
    | — it reads as a supported option and fails at the first upload.
    |
    | Object storage is a real omission and it is recorded as one in
    | docs/LIMITATIONS.md, not papered over here.
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
         * Task, comment, milestone and wiki attachments.
         *
         * This disk lives outside the web root and deliberately sets serve => false:
         * there is no framework-generated URL for anything on it. Every download goes
         * through App\Http\Controllers\AttachmentController, which resolves the
         * attachment, runs the policy for the acting user, and streams the bytes.
         * Nothing here is ever reachable by guessing a path.
         */
        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Genuinely public, non-sensitive imagery only: user avatars, workspace logos
         * and project logos. Reachable through the public/storage symlink. Never put an
         * attachment here.
         */
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
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
