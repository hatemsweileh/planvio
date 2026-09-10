<?php

declare(strict_types=1);

namespace App\Services\Install;

use SensitiveParameter;

/**
 * The SMTP account Planvio will send from, or the decision not to configure one.
 *
 * Email is skippable (spec §71). A skipped configuration is represented by
 * {@see self::skipped()} rather than by null, so every caller handles the same type and the
 * `.env` still gets a defensible mailer: `log`, which writes the message into
 * `storage/logs` instead of pretending to have sent it.
 *
 * `scheme` follows Laravel 11+ naming — `smtp`, `smtps`, or none for opportunistic STARTTLS.
 * The screen still calls it "Encryption", because that is what the host's control panel calls
 * it.
 */
final readonly class MailCredentials
{
    public const ENCRYPTION_NONE = 'none';

    public const ENCRYPTION_TLS = 'tls';

    public const ENCRYPTION_SSL = 'ssl';

    private function __construct(
        public bool $configured,
        public string $host,
        public int $port,
        public string $encryption,
        public string $username,
        #[SensitiveParameter]
        public string $password,
        public string $fromAddress,
        public string $fromName,
    ) {}

    public static function smtp(
        string $host,
        int $port,
        string $encryption,
        string $username,
        #[SensitiveParameter]
        string $password,
        string $fromAddress,
        string $fromName,
    ): self {
        return new self(true, trim($host), $port, $encryption, trim($username), $password, trim($fromAddress), trim($fromName));
    }

    public static function skipped(string $fromAddress = '', string $fromName = ''): self
    {
        return new self(false, '', 587, self::ENCRYPTION_TLS, '', '', trim($fromAddress), trim($fromName));
    }

    /**
     * @return array<string, string>
     */
    public static function encryptionOptions(): array
    {
        return [
            self::ENCRYPTION_TLS => __('STARTTLS (usually port 587)'),
            self::ENCRYPTION_SSL => __('SSL/TLS (usually port 465)'),
            self::ENCRYPTION_NONE => __('None (not recommended)'),
        ];
    }

    /**
     * Laravel's transport scheme, or null to let Symfony negotiate STARTTLS itself.
     */
    public function scheme(): ?string
    {
        return match ($this->encryption) {
            self::ENCRYPTION_SSL => 'smtps',
            self::ENCRYPTION_NONE => 'smtp',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function mailerConfig(): array
    {
        return [
            'transport' => 'smtp',
            'scheme' => $this->scheme(),
            'url' => null,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username === '' ? null : $this->username,
            'password' => $this->password === '' ? null : $this->password,
            'timeout' => 10,
            'local_domain' => null,
            'verify_peer' => $this->encryption !== self::ENCRYPTION_NONE,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function toEnv(): array
    {
        if (! $this->configured) {
            return [
                'MAIL_MAILER' => 'log',
                'MAIL_FROM_ADDRESS' => $this->fromAddress,
                'MAIL_FROM_NAME' => $this->fromName,
            ];
        }

        return [
            'MAIL_MAILER' => 'smtp',
            'MAIL_HOST' => $this->host,
            'MAIL_PORT' => (string) $this->port,
            'MAIL_USERNAME' => $this->username,
            'MAIL_PASSWORD' => $this->password,
            'MAIL_SCHEME' => $this->scheme() ?? 'null',
            'MAIL_FROM_ADDRESS' => $this->fromAddress,
            'MAIL_FROM_NAME' => $this->fromName,
        ];
    }
}
