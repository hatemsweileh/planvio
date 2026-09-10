<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Enums\AiDriver;
use App\Enums\AiMode;
use Illuminate\Contracts\Session\Session;

/**
 * What the wizard has been told so far.
 *
 * ### Why the session and not the checkpoint file
 *
 * Four of the nine screens collect a credential — the database password, the administrator's
 * password, the SMTP password and the AI key. None of them belongs in a file on disk beside
 * the lock, which is why {@see InstallCheckpoint} holds no secrets at all: it records which
 * *steps* finished so a retry does not migrate twice, and nothing else.
 *
 * These values live in the session instead, which {@see InstallerEnvironment} pins to the
 * file driver with `session.encrypt` on for the length of the installation — so the payload
 * on disk is ciphertext under the application key, and it is discarded the moment the lock
 * is written.
 *
 * ### Building the plan
 *
 * The Livewire steps validate their own input and hand over plain arrays. {@see self::plan()}
 * is the single place that turns those arrays into typed value objects, and it returns null
 * rather than a half-built plan if a screen has not been completed — so the install engine
 * only ever receives something whole.
 */
final class InstallState
{
    private const KEY = 'planvio.installer';

    public function __construct(
        private readonly Session $session,
        private readonly InstallCheckpoint $checkpoint,
    ) {}

    /* ------------------------------------------------------------------ *
     * Per-step data
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $data
     */
    public function put(WizardStep $step, array $data): void
    {
        $this->session->put(self::KEY.'.'.$step->value, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(WizardStep $step): array
    {
        $data = $this->session->get(self::KEY.'.'.$step->value);

        return is_array($data) ? $data : [];
    }

    public function has(WizardStep $step): bool
    {
        return $this->get($step) !== [];
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }

    /* ------------------------------------------------------------------ *
     * Navigation
     * ------------------------------------------------------------------ */

    /**
     * The furthest screen this session is allowed to open.
     *
     * Deep-linking to the administrator screen before the database has been tested would
     * produce a form whose Continue button leads nowhere, so every step asks this first and
     * redirects back to the earliest one still outstanding.
     */
    public function furthestAvailable(): WizardStep
    {
        if ($this->checkpoint->isFinished()) {
            return WizardStep::Finish;
        }

        // While the engine is mid-run and healthy, the only meaningful screen is the one
        // watching it. After a failure the opposite is true: the reason is usually a setting,
        // so every screen opens again and the checkpoint is rewound to match the edit.
        if ($this->checkpoint->hasStarted() && $this->checkpoint->failure() === null) {
            return WizardStep::Install;
        }

        foreach (WizardStep::sequence() as $step) {
            if ($step->collectsData() && ! $this->has($step)) {
                return $step;
            }
        }

        return WizardStep::Install;
    }

    public function allows(WizardStep $requested): bool
    {
        return ! $this->furthestAvailable()->isBefore($requested);
    }

    /* ------------------------------------------------------------------ *
     * The plan
     * ------------------------------------------------------------------ */

    public function isComplete(): bool
    {
        foreach (WizardStep::sequence() as $step) {
            if ($step->collectsData() && ! $this->has($step)) {
                return false;
            }
        }

        return true;
    }

    public function plan(): ?InstallPlan
    {
        if (! $this->isComplete()) {
            return null;
        }

        return new InstallPlan(
            database: $this->databaseCredentials(),
            application: $this->applicationSettings(),
            administrator: $this->administratorAccount(),
            mail: $this->mailCredentials(),
            ai: $this->aiCredentials(),
        );
    }

    private function databaseCredentials(): DatabaseCredentials
    {
        $data = $this->get(WizardStep::Database);

        return DatabaseCredentials::mysql(
            host: $this->string($data, 'host', '127.0.0.1'),
            port: $this->int($data, 'port', 3306),
            database: $this->string($data, 'database'),
            username: $this->string($data, 'username'),
            password: $this->string($data, 'password'),
        );
    }

    private function applicationSettings(): ApplicationSettings
    {
        $data = $this->get(WizardStep::Application);

        return new ApplicationSettings(
            name: $this->string($data, 'name', 'Planvio'),
            url: $this->string($data, 'url', 'http://localhost'),
            timezone: $this->string($data, 'timezone', 'UTC'),
            locale: $this->string($data, 'locale', 'en'),
            currency: strtoupper($this->string($data, 'currency', 'USD')),
            dateFormat: $this->string($data, 'date_format', 'Y-m-d'),
            environment: $this->string($data, 'environment') === ApplicationSettings::ENVIRONMENT_LOCAL
                ? ApplicationSettings::ENVIRONMENT_LOCAL
                : ApplicationSettings::ENVIRONMENT_PRODUCTION,
        );
    }

    private function administratorAccount(): AdministratorAccount
    {
        $data = $this->get(WizardStep::Administrator);

        return new AdministratorAccount(
            name: $this->string($data, 'name'),
            email: $this->string($data, 'email'),
            password: $this->string($data, 'password'),
        );
    }

    private function mailCredentials(): MailCredentials
    {
        $data = $this->get(WizardStep::Email);
        $from = $this->string($data, 'from_address', $this->administratorAccount()->email);
        $fromName = $this->string($data, 'from_name', $this->applicationSettings()->name);

        if (! ($data['configured'] ?? false)) {
            return MailCredentials::skipped($from, $fromName);
        }

        return MailCredentials::smtp(
            host: $this->string($data, 'host'),
            port: $this->int($data, 'port', 587),
            encryption: $this->string($data, 'encryption', MailCredentials::ENCRYPTION_TLS),
            username: $this->string($data, 'username'),
            password: $this->string($data, 'password'),
            fromAddress: $from,
            fromName: $fromName,
        );
    }

    private function aiCredentials(): AiCredentials
    {
        $data = $this->get(WizardStep::Ai);

        if (! ($data['enabled'] ?? false)) {
            return AiCredentials::disabled();
        }

        return AiCredentials::enabled(
            driver: AiDriver::tryFrom($this->string($data, 'driver')) ?? AiDriver::OpenAi,
            baseUrl: $this->string($data, 'base_url') ?: null,
            apiKey: $this->string($data, 'api_key') ?: null,
            model: $this->string($data, 'model') ?: null,
            mode: AiMode::tryFrom($this->string($data, 'mode')) ?? AiMode::Assistant,
        );
    }

    /* ------------------------------------------------------------------ *
     * Coercion
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $data
     */
    private function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function int(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
