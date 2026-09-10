<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Foundation\Application;

/**
 * Release identity.
 *
 * APP_VERSION is the shipped release. DB_VERSION is the schema generation the installer and
 * updater compare against `settings['db_version']` to decide whether migrations must run;
 * bump it whenever a release adds migrations.
 */
final class Version
{
    public const APP_VERSION = '1.0.0';

    public const DB_VERSION = '1.0.0';

    private function __construct() {}

    public static function app(): string
    {
        return self::APP_VERSION;
    }

    public static function db(): string
    {
        return self::DB_VERSION;
    }

    public static function laravel(): string
    {
        return Application::VERSION;
    }

    public static function php(): string
    {
        return PHP_VERSION;
    }

    /**
     * @return array{app: string, db: string, laravel: string, php: string}
     */
    public static function full(): array
    {
        return [
            'app' => self::app(),
            'db' => self::db(),
            'laravel' => self::laravel(),
            'php' => self::php(),
        ];
    }
}
