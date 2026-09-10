<?php

declare(strict_types=1);

namespace App\Services\Install;

/**
 * What the installation calls itself and how it formats things.
 *
 * These values land in two places at once, deliberately: `.env` (so the framework, the mail
 * templates and the queue agree on them) and the first workspace's own columns (so a team
 * that later changes its date format changes it for itself, not for the server).
 *
 * `environment` is `production` or `local` and nothing else. It drives `APP_DEBUG`, which is
 * the single setting most likely to turn a misconfiguration into a disclosure, so it is not
 * a free-text field an administrator can typo into `produciton` and quietly get debug pages.
 */
final readonly class ApplicationSettings
{
    public const ENVIRONMENT_PRODUCTION = 'production';

    public const ENVIRONMENT_LOCAL = 'local';

    public function __construct(
        public string $name,
        public string $url,
        public string $timezone,
        public string $locale,
        public string $currency,
        public string $dateFormat,
        public string $environment = self::ENVIRONMENT_PRODUCTION,
    ) {}

    public function isProduction(): bool
    {
        return $this->environment !== self::ENVIRONMENT_LOCAL;
    }

    /**
     * The workspace the first administrator lands in takes the installation's name. It is
     * renameable from workspace settings the moment they sign in, and picking anything else
     * here would mean asking for a name before there is any context for choosing one.
     */
    public function workspaceName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    public function toEnv(): array
    {
        return [
            'APP_NAME' => $this->name,
            'APP_ENV' => $this->environment,
            'APP_DEBUG' => $this->isProduction() ? 'false' : 'true',
            'APP_URL' => rtrim($this->url, '/'),
            'APP_LOCALE' => $this->locale,
            'APP_FALLBACK_LOCALE' => 'en',
            'APP_TIMEZONE' => $this->timezone,
            'PLANVIO_CURRENCY' => $this->currency,
            'PLANVIO_DATE_FORMAT' => $this->dateFormat,
            // Sessions may travel over plain HTTP on a local install; insisting on the secure
            // flag there would make the wizard sign people out of their own laptop.
            'SESSION_SECURE_COOKIE' => str_starts_with($this->url, 'https://') ? 'true' : 'false',
        ];
    }

    /**
     * The date formats offered on the application step, keyed by the PHP format string.
     *
     * @return array<string, string>
     */
    public static function dateFormats(): array
    {
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'd M Y', 'M j, Y'];
        $sample = mktime(0, 0, 0, 3, 9, 2026) ?: time();

        $options = [];

        foreach ($formats as $format) {
            $options[$format] = date($format, $sample).' — '.$format;
        }

        return $options;
    }
}
