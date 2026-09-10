<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The checks behind System Health.
 *
 * # The governing rule: never report green without evidence
 *
 * docs/CPANEL.md makes this promise in as many words, and the scheduler check is where it
 * matters most. There is no way to ask a shared host "is cron running"; the only honest answer
 * comes from `planvio:heartbeat`, which writes `system.scheduler.last_run_at` every minute. A
 * timestamp that is advancing proves cron is alive. Its absence proves cron has never run —
 * which looks exactly like a quiet week, and is the failure people discover by missing a
 * deadline. So a missing heartbeat is `Failed`, a stale one is `Warning`, and neither is ever
 * softened into "probably fine".
 *
 * The same rule shapes the rest. A check that cannot reach what it is testing returns `Warning`
 * with "could not be verified", never `Healthy`. Every check carries the observation it is based
 * on so the reader can disagree with it.
 *
 * # Everything is wrapped
 *
 * This page is opened *because* something is broken. A probe that threw would replace the
 * diagnosis with a stack trace, so each one catches and reports the failure as its result.
 */
final class HealthReport
{
    /** A heartbeat older than this means cron has stopped, not that it is between ticks. */
    private const SCHEDULER_STALE_MINUTES = 15;

    /** Beyond this, the queue is not being drained — the worker cron is missing or failing. */
    private const QUEUE_BACKLOG_WARNING = 500;

    /**
     * @return list<HealthCheck>
     */
    public function all(): array
    {
        return [
            $this->database(),
            $this->storage(),
            $this->cache(),
            $this->applicationKey(),
            $this->mail(),
            $this->queue(),
            $this->scheduler(),
            $this->ai(),
            $this->environmentFile(),
        ];
    }

    /**
     * The worst status in the report — what the page leads with.
     *
     * @param list<HealthCheck> $checks
     */
    public function overall(array $checks): HealthStatus
    {
        $worst = HealthStatus::Healthy;

        foreach ($checks as $check) {
            if ($check->status->isWorseThan($worst)) {
                $worst = $check->status;
            }
        }

        return $worst;
    }

    /* ------------------------------------------------------------------ *
     * Checks
     * ------------------------------------------------------------------ */

    private function database(): HealthCheck
    {
        $label = __('Database');

        try {
            $started = microtime(true);
            $connection = DB::connection();
            $connection->select('select 1');
            $ms = (int) round((microtime(true) - $started) * 1000);

            $facts = SystemFacts::database();
            $schema = SystemFacts::databaseVersion();

            $evidence = [
                __(':engine :version answered in :ms ms.', [
                    'engine' => $facts['driver'],
                    'version' => $facts['version'],
                    'ms' => $ms,
                ]),
                __('Database: :name', ['name' => $facts['database']]),
            ];

            if (! $schema['matches']) {
                return HealthCheck::warning(
                    'database',
                    $label,
                    __('Connected, but the schema is behind the code.'),
                    [
                        ...$evidence,
                        __('Schema is at :installed; this release expects :expected.', [
                            'installed' => $schema['installed'],
                            'expected' => $schema['expected'],
                        ]),
                    ],
                    __('Run the pending migrations, or open the site and let the upgrade screen run them.'),
                );
            }

            return HealthCheck::healthy('database', $label, __('Connected and answering.'), $evidence);
        } catch (Throwable $exception) {
            return HealthCheck::failed(
                'database',
                $label,
                __('The database could not be reached.'),
                [$this->safeMessage($exception)],
                __('Check the database credentials in .env, and that the database server is running.'),
            );
        }
    }

    private function storage(): HealthCheck
    {
        $label = __('Storage is writable');

        $paths = [];

        foreach ((array) config('planvio.install.writable_paths', []) as $relative) {
            if (is_string($relative)) {
                $paths[$relative] = base_path($relative);
            }
        }

        $unwritable = [];
        $evidence = [];

        foreach ($paths as $relative => $absolute) {
            if (SystemFacts::isWritable($absolute)) {
                $evidence[] = __(':path is writable.', ['path' => $relative]);

                continue;
            }

            $unwritable[] = $relative;
        }

        if ($unwritable !== []) {
            return HealthCheck::failed(
                'storage',
                $label,
                __('Planvio cannot write to :count of its directories.', ['count' => count($unwritable)]),
                [
                    ...$evidence,
                    __('Not writable: :paths', ['paths' => implode(', ', $unwritable)]),
                ],
                __('Set those directories to 0755 (or 0775 if PHP runs as a different user), recursively.'),
            );
        }

        return HealthCheck::healthy(
            'storage',
            $label,
            __('Every required directory is writable.'),
            $evidence,
        );
    }

    /**
     * A real round trip, not a config read: a cache table that has not been migrated, or a
     * store pointing somewhere unwritable, both look perfectly configured.
     */
    private function cache(): HealthCheck
    {
        $label = __('Cache');
        $store = (string) config('cache.default', 'database');
        $key = 'planvio:health:'.Str::random(12);
        $value = Str::random(16);

        try {
            Cache::store($store)->put($key, $value, 30);
            $read = Cache::store($store)->get($key);
            Cache::store($store)->forget($key);

            if ($read !== $value) {
                return HealthCheck::failed(
                    'cache',
                    $label,
                    __('The cache accepted a value and returned something else.'),
                    [__('Store: :store', ['store' => $store])],
                    __('Check the cache configuration; a shared cache directory or table may be misconfigured.'),
                );
            }

            return HealthCheck::healthy(
                'cache',
                $label,
                __('Wrote and read a value successfully.'),
                [__('Store: :store', ['store' => $store])],
            );
        } catch (Throwable $exception) {
            return HealthCheck::failed(
                'cache',
                $label,
                __('The cache could not be written.'),
                [
                    __('Store: :store', ['store' => $store]),
                    $this->safeMessage($exception),
                ],
                __('For the database store, confirm the `cache` table exists. For the file store, confirm storage/framework/cache is writable.'),
            );
        }
    }

    /**
     * The application key protects every encrypted column — AI credentials, two-factor secrets,
     * encrypted settings. Its absence is not a configuration nicety.
     */
    private function applicationKey(): HealthCheck
    {
        $label = __('Application key');
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            return HealthCheck::failed(
                'app_key',
                $label,
                __('No application key is set.'),
                [__('APP_KEY is empty in .env.')],
                __('Generate one with `php artisan key:generate`. Existing encrypted values will not survive a change of key.'),
            );
        }

        $cipher = (string) config('app.cipher', 'AES-256-CBC');
        $raw = Str::startsWith($key, 'base64:') ? base64_decode(Str::after($key, 'base64:'), true) : $key;
        $length = is_string($raw) ? mb_strlen($raw, '8bit') : 0;
        $expected = Str::contains($cipher, '128') ? 16 : 32;

        if ($length !== $expected) {
            return HealthCheck::failed(
                'app_key',
                $label,
                __('The application key is the wrong length for the configured cipher.'),
                [__(':cipher needs a :expected-byte key; this one is :length bytes.', [
                    'cipher' => $cipher,
                    'expected' => $expected,
                    'length' => $length,
                ])],
                __('Generate a new key with `php artisan key:generate`.'),
            );
        }

        return HealthCheck::healthy(
            'app_key',
            $label,
            __('Set, and the right length for the cipher.'),
            [__('Cipher: :cipher', ['cipher' => $cipher])],
        );
    }

    /**
     * Configuration only. Nothing here sends a message: a health page that emailed somebody
     * every time it was opened would be its own problem, and the "send a test message" button
     * belongs on the mail settings screen where an address can be chosen.
     */
    private function mail(): HealthCheck
    {
        $label = __('Email');
        $mailer = (string) config('mail.default', 'smtp');
        $from = config('mail.from.address');

        if ($mailer === 'log') {
            return HealthCheck::warning(
                'mail',
                $label,
                __('Mail is written to the log file, not sent.'),
                [__('Mailer: log')],
                __('Configure SMTP before inviting anybody: invitations, password resets and reminders will not reach them.'),
            );
        }

        if ($mailer === 'array' || $mailer === 'null') {
            return HealthCheck::warning(
                'mail',
                $label,
                __('Mail is discarded.'),
                [__('Mailer: :mailer', ['mailer' => $mailer])],
                __('Configure SMTP. Nothing Planvio sends will arrive.'),
            );
        }

        $evidence = [__('Mailer: :mailer', ['mailer' => $mailer])];
        $host = config('mail.mailers.'.$mailer.'.host');

        if (is_string($host) && $host !== '') {
            $port = config('mail.mailers.'.$mailer.'.port');
            $evidence[] = __('Host: :host:port', ['host' => $host, 'port' => is_scalar($port) ? $port : '?']);
        }

        if (! is_string($from) || $from === '') {
            return HealthCheck::warning(
                'mail',
                $label,
                __('No "from" address is configured.'),
                $evidence,
                __('Set MAIL_FROM_ADDRESS. Many providers reject a message with no sender.'),
            );
        }

        $evidence[] = __('From: :address', ['address' => $from]);

        // Configured is not the same as working, and this check is careful not to claim it is.
        return HealthCheck::healthy(
            'mail',
            $label,
            __('Configured. Delivery itself is only proven by sending a test message.'),
            $evidence,
        );
    }

    private function queue(): HealthCheck
    {
        $label = __('Queue');
        $facts = SystemFacts::queue();
        $evidence = [__('Driver: :driver (:connection)', [
            'driver' => $facts['driver'],
            'connection' => $facts['connection'],
        ])];

        if ($facts['driver'] === 'sync') {
            return HealthCheck::warning(
                'queue',
                $label,
                __('Queued work runs inline, inside the web request.'),
                $evidence,
                __('Set QUEUE_CONNECTION=database and add the queue worker cron entry, or a slow AI run will hold a browser request open until PHP times out.'),
            );
        }

        if ($facts['pending'] === null) {
            return HealthCheck::warning(
                'queue',
                $label,
                __('The queue could not be inspected.'),
                [...$evidence, __('The jobs table could not be read.')],
                __('Confirm the `jobs` table exists — it is created by the migrations.'),
            );
        }

        $evidence[] = __(':count jobs waiting.', ['count' => $facts['pending']]);
        $failed = $facts['failed'] ?? 0;
        $evidence[] = __(':count failed jobs.', ['count' => $failed]);

        if ($facts['pending'] > self::QUEUE_BACKLOG_WARNING) {
            return HealthCheck::warning(
                'queue',
                $label,
                __('The queue is backing up.'),
                $evidence,
                __('The queue worker cron entry is probably missing or failing. Copy it from System Information.'),
            );
        }

        if ($failed > 0) {
            return HealthCheck::warning(
                'queue',
                $label,
                __('Some jobs have failed.'),
                $evidence,
                __('Inspect them with `php artisan queue:failed`. Failed jobs are not retried on their own.'),
            );
        }

        return HealthCheck::healthy('queue', $label, __('Draining normally.'), $evidence);
    }

    /**
     * The one check that must never be green without proof. See the class docblock.
     */
    private function scheduler(): HealthCheck
    {
        $label = __('Scheduler (cron)');
        $lastRun = SystemFacts::schedulerLastRun();

        if ($lastRun === null) {
            return HealthCheck::failed(
                'scheduler',
                $label,
                __('The scheduler has never run.'),
                [__('The setting system.scheduler.last_run_at has no value, so no heartbeat has ever been written.')],
                __('Add the scheduler cron entry from System Information. Until it runs, reminders, recurring tasks, AI automations and nightly maintenance do nothing.'),
            );
        }

        $minutes = (int) $lastRun->diffInMinutes(Carbon::now(), absolute: true);

        $evidence = [
            __('Heartbeat written :ago (:time).', [
                'ago' => $lastRun->diffForHumans(),
                'time' => $lastRun->toDayDateTimeString(),
            ]),
        ];

        if ($minutes > self::SCHEDULER_STALE_MINUTES) {
            return HealthCheck::failed(
                'scheduler',
                $label,
                __('The scheduler has stopped.'),
                [
                    ...$evidence,
                    __('The heartbeat runs every minute; :minutes minutes is not a gap between ticks.', ['minutes' => $minutes]),
                ],
                __('Check the cron entry still exists and its PHP path is correct. System Information shows the correct command.'),
            );
        }

        return HealthCheck::healthy('scheduler', $label, __('Running.'), $evidence);
    }

    private function ai(): HealthCheck
    {
        $label = __('AI configuration');
        $facts = SystemFacts::ai();

        if (! $facts['enabled']) {
            return HealthCheck::healthy(
                'ai',
                $label,
                __('AI is switched off, and Planvio is fully usable without it.'),
                [__('AI_ENABLED is false, so no AI route, job, tool or provider call runs anywhere.')],
            );
        }

        if ($facts['provider'] === null) {
            return HealthCheck::warning(
                'ai',
                $label,
                __('AI is enabled but no active provider is configured.'),
                [__('Nothing will answer an AI request.')],
                __('Add a provider under AI → Providers, or set AI_ENABLED=false.'),
            );
        }

        $evidence = [
            __('Provider: :name (:driver)', ['name' => $facts['provider'], 'driver' => $facts['driver']]),
            __('Model: :model', ['model' => $facts['model'] ?? '—']),
        ];

        if (! $facts['has_key']) {
            return HealthCheck::warning(
                'ai',
                $label,
                __('The active provider has no API key stored.'),
                [...$evidence, __('No credential is stored for this provider.')],
                __('Add the key under AI → Providers. A local endpoint that needs no key can ignore this.'),
            );
        }

        // Deliberately not a live call: opening a health page must not spend money or hang on
        // a slow endpoint. "Test connection" on the provider does that, on request.
        return HealthCheck::healthy(
            'ai',
            $label,
            __('A provider is configured. Use "Test connection" to prove it answers.'),
            [...$evidence, __('An API key is stored.')],
        );
    }

    /**
     * Fetch this installation's own `/.env` over HTTP and see what the web server says.
     *
     * docs/CPANEL.md tells people to check this by hand, and this is the same check. It is worth
     * automating because the failure is catastrophic and completely silent: a misconfigured
     * document root or a host that ignores `.htaccess` serves the database credentials and the
     * application key to anybody who asks, and nothing in the application would ever notice.
     *
     * Certificate verification is off for this one request. The question is what the server
     * returns for that path, and a self-signed certificate — common on a staging hostname — must
     * not turn a real answer into "could not be verified".
     */
    private function environmentFile(): HealthCheck
    {
        $label = __('.env is not served');
        $base = rtrim((string) config('app.url'), '/');

        if ($base === '') {
            return HealthCheck::warning(
                'env_file',
                $label,
                __('Could not be checked: no APP_URL is configured.'),
                [],
                __('Set APP_URL to the address this installation is served from.'),
            );
        }

        $url = $base.'/.env';

        try {
            $response = Http::withoutVerifying()
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(3)
                ->timeout(5)
                ->get($url);

            $status = $response->status();
        } catch (Throwable $exception) {
            return HealthCheck::warning(
                'env_file',
                $label,
                __('Could not be checked: this installation could not reach itself.'),
                [
                    __('Requested :url', ['url' => $url]),
                    $this->safeMessage($exception),
                ],
                __('Open :url in a browser. It must not download a file.', ['url' => $url]),
            );
        }

        if ($status === 200) {
            return HealthCheck::failed(
                'env_file',
                $label,
                __('The .env file is being served over HTTP.'),
                [__('GET :url returned 200.', ['url' => $url])],
                __('This exposes your database credentials and application key. Point the document root at public/ — see docs/CPANEL.md, step 4 — or restore the .htaccess that ships with the release.'),
            );
        }

        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            return HealthCheck::warning(
                'env_file',
                $label,
                __('The request was redirected rather than refused.'),
                [__('GET :url returned :status.', ['url' => $url, 'status' => $status])],
                __('Follow the redirect yourself and confirm it does not end at the file.'),
            );
        }

        return HealthCheck::healthy(
            'env_file',
            $label,
            __('The web server refuses to serve it.'),
            [__('GET :url returned :status.', ['url' => $url, 'status' => $status])],
        );
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * An exception message safe to render.
     *
     * A database connection failure message routinely contains the DSN, and that contains the
     * username. It is trimmed hard rather than passed through (CLAUDE.md rule 4), and a password
     * would never survive the truncation intact.
     */
    private function safeMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            return class_basename($exception);
        }

        $message = (string) preg_replace('/(password|pwd|secret|token|api[_-]?key)=\S+/i', '$1=***', $message);

        return Str::limit($message, 160);
    }
}
