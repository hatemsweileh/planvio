<?php

declare(strict_types=1);

namespace App\Services\Install;

use SensitiveParameter;

/**
 * The connection the installer is being asked to use.
 *
 * The wizard only ever offers MySQL/MariaDB — that is what ARCHITECTURE.md §5 specifies and
 * what `pdo_mysql` in the requirements list is there for. SQLite is supported by the value
 * object because the connection settings are generic and because it is what lets the
 * installer be tested end to end without a database server; no installer screen produces it.
 */
final readonly class DatabaseCredentials
{
    public const DRIVER_MYSQL = 'mysql';

    public const DRIVER_SQLITE = 'sqlite';

    private function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        #[SensitiveParameter]
        public string $password,
    ) {}

    public static function mysql(
        string $host,
        int $port,
        string $database,
        string $username,
        #[SensitiveParameter]
        string $password,
    ): self {
        return new self(self::DRIVER_MYSQL, trim($host), $port, trim($database), trim($username), $password);
    }

    /**
     * `$database` is an absolute path to the file.
     */
    public static function sqlite(string $database): self
    {
        return new self(self::DRIVER_SQLITE, '', 0, $database, '', '');
    }

    public function isMysql(): bool
    {
        return $this->driver === self::DRIVER_MYSQL;
    }

    public function dsn(): string
    {
        if (! $this->isMysql()) {
            return 'sqlite:'.$this->database;
        }

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->database,
        );
    }

    /**
     * The connection name this occupies in `config('database.connections')`.
     */
    public function connectionName(): string
    {
        return $this->driver;
    }

    /**
     * A complete connection definition, merged over whatever `config/database.php` ships.
     *
     * @return array<string, mixed>
     */
    public function connectionConfig(): array
    {
        if (! $this->isMysql()) {
            return [
                'driver' => 'sqlite',
                'database' => $this->database,
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => null,
                'journal_mode' => null,
                'synchronous' => null,
            ];
        }

        return [
            'driver' => 'mysql',
            'url' => null,
            'host' => $this->host,
            'port' => (string) $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => [],
        ];
    }

    /**
     * The credentials as `.env` keys. Used by the writer, never by anything that renders.
     *
     * @return array<string, string>
     */
    public function toEnv(): array
    {
        if (! $this->isMysql()) {
            return [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $this->database,
            ];
        }

        return [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $this->host,
            'DB_PORT' => (string) $this->port,
            'DB_DATABASE' => $this->database,
            'DB_USERNAME' => $this->username,
            'DB_PASSWORD' => $this->password,
        ];
    }
}
