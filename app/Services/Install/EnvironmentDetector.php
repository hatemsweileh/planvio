<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Models\Locale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * What the installer can work out about the server on its own.
 *
 * Every value here is a *suggestion*. The application URL in particular is derived from the
 * `Host` header, which is attacker-controlled on any server that answers for more than one
 * name — and `APP_URL` ends up in password-reset links and notification mail. So detection
 * fills the form field, the administrator confirms or corrects it, and the value is validated
 * on submit as an absolute http(s) URL (spec §138: never blindly trust the detected value).
 */
final class EnvironmentDetector
{
    public function __construct(private readonly Request $request) {}

    /**
     * The address this installation appears to answer on, without a trailing slash.
     *
     * `getSchemeAndHttpHost()` plus the base path, so an install in a subdirectory suggests
     * `https://example.com/planvio` rather than the bare host.
     */
    public function appUrl(): string
    {
        $base = rtrim($this->request->getSchemeAndHttpHost().$this->request->getBaseUrl(), '/');

        return $base === '' ? 'http://localhost' : $base;
    }

    public function appName(): string
    {
        return (string) config('planvio.brand.name', 'Planvio');
    }

    public function https(): bool
    {
        return $this->request->isSecure();
    }

    public function timezone(): string
    {
        $timezone = (string) config('planvio.defaults.workspace.timezone', 'UTC');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    /**
     * The language to offer as the installation's default.
     *
     * The wizard's own language first, when this release ships a catalogue for it: somebody
     * who chose Arabic in the installer shell has already answered this question, and
     * offering `en` on the next screen would make that choice cosmetic — the wizard in
     * Arabic, the installation it writes in English.
     */
    public function locale(): string
    {
        $chosen = App::getLocale();

        if (Locale::shipped()->has($chosen)) {
            return $chosen;
        }

        return (string) config('planvio.defaults.workspace.locale', 'en');
    }

    public function currency(): string
    {
        return strtoupper(substr((string) config('planvio.defaults.workspace.currency', 'USD'), 0, 3));
    }

    public function dateFormat(): string
    {
        return (string) config('planvio.defaults.workspace.date_format', 'Y-m-d');
    }

    /**
     * Production unless the request plainly is not: a loopback host on an unencrypted
     * connection is somebody's laptop, and defaulting that to `production` only means they
     * spend an afternoon wondering why there are no error messages.
     */
    public function environment(): string
    {
        $host = strtolower($this->request->getHost());
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost');

        return $local && ! $this->https() ? 'local' : 'production';
    }

    public function documentRoot(): ?string
    {
        $root = $this->request->server('DOCUMENT_ROOT');

        return is_string($root) && $root !== '' ? $root : null;
    }

    public function serverSoftware(): ?string
    {
        $software = $this->request->server('SERVER_SOFTWARE');

        return is_string($software) && $software !== '' ? $software : null;
    }

    public function databaseDriver(): string
    {
        return extension_loaded('pdo_mysql') ? 'mysql' : 'unavailable';
    }

    /**
     * The read-only facts shown beside the requirements table.
     *
     * @return array<string, string>
     */
    public function summary(): array
    {
        return array_filter([
            __('Domain') => $this->request->getHost(),
            __('Connection') => $this->https() ? __('HTTPS') : __('HTTP — not encrypted'),
            __('PHP version') => PHP_VERSION,
            __('Database driver') => $this->databaseDriver() === 'mysql'
                ? __('MySQL / MariaDB (pdo_mysql)')
                : __('None detected'),
            __('Web server') => $this->serverSoftware(),
            __('Document root') => $this->documentRoot(),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
    }
}
