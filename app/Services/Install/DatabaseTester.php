<?php

declare(strict_types=1);

namespace App\Services\Install;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Throwable;

/**
 * "Test connection", answered by actually connecting.
 *
 * A real PDO handle, not a `ping`: the four things that go wrong on shared hosting — the
 * wrong password, a database name without the cPanel account prefix, a user who was created
 * but never *added* to the database, and `localhost` where the host wanted `127.0.0.1` —
 * are all indistinguishable until the server answers, and each has a different fix.
 *
 * MySQL's own error numbers are the most reliable signal available, so they are translated
 * one by one into the sentence that repairs that specific mistake. Anything unrecognised gets
 * a neutral message plus a reference id; the driver's own text goes to the log, never to the
 * browser, because a connection error is free to quote the credentials it rejected.
 */
final class DatabaseTester
{
    /**
     * Seconds to wait for the server. Short on purpose: a wrong host on a filtered network
     * hangs until the TCP timeout, and a wizard that appears frozen for 30 seconds reads as
     * broken software rather than as a typo.
     */
    private const TIMEOUT_SECONDS = 5;

    /**
     * MariaDB and MySQL floors from INSTALLATION.md. Below these, Planvio's migrations still
     * run but a few index and JSON behaviours differ, so it is a warning, not a refusal.
     */
    private const MIN_MARIADB = '10.6';

    private const MIN_MYSQL = '5.7';

    public function test(DatabaseCredentials $credentials): ConnectionTest
    {
        if ($credentials->database === '') {
            return ConnectionTest::failed(__('Enter the name of the database Planvio should use.'));
        }

        if ($credentials->isMysql() && $credentials->host === '') {
            return ConnectionTest::failed(__('Enter the database host. On cPanel this is almost always 127.0.0.1.'));
        }

        if ($credentials->isMysql() && ! extension_loaded('pdo_mysql')) {
            return ConnectionTest::failed(
                __('This server cannot talk to MySQL: the pdo_mysql extension is not loaded.'),
                __('Enable pdo_mysql in “Select PHP Version” → Extensions and reload this page.'),
            );
        }

        try {
            $pdo = new PDO($credentials->dsn(), $credentials->username, $credentials->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => self::TIMEOUT_SECONDS,
                PDO::ATTR_PERSISTENT => false,
            ]);
        } catch (PDOException $e) {
            return $this->explain($e, $credentials);
        } catch (Throwable $e) {
            return $this->unexpected($e);
        }

        return $this->inspect($pdo, $credentials);
    }

    /* ------------------------------------------------------------------ *
     * Success path
     * ------------------------------------------------------------------ */

    private function inspect(PDO $pdo, DatabaseCredentials $credentials): ConnectionTest
    {
        $version = $this->serverVersion($pdo);
        $tables = $this->tableCount($pdo, $credentials);

        $where = $credentials->isMysql()
            ? __('Connected to :database on :host.', ['database' => $credentials->database, 'host' => $credentials->host])
            : __('Connected to :database.', ['database' => basename($credentials->database)]);

        if ($tables > 0) {
            return ConnectionTest::warned(
                $where,
                __('This database already contains :count tables. Installing into it will add Planvio\'s tables alongside them, and any table Planvio owns will be overwritten. Use an empty database unless you are reinstalling on purpose.', ['count' => $tables]),
            );
        }

        // Only MySQL and MariaDB have a floor to be below. SQLite reports its own version
        // scheme, which has nothing to say about either.
        $tooOld = $credentials->isMysql() ? $this->tooOld($version) : null;

        if ($tooOld !== null) {
            return ConnectionTest::warned($where, $tooOld);
        }

        return ConnectionTest::passed(
            $where,
            $version === null ? null : __('Server version :version.', ['version' => $version]),
        );
    }

    private function serverVersion(PDO $pdo): ?string
    {
        try {
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return null;
        }

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * A caveat about the server version, or null when it is fine or unknown.
     */
    private function tooOld(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $numeric = preg_match('/(\d+\.\d+(?:\.\d+)?)/', $version, $matches) === 1 ? $matches[1] : null;

        if ($numeric === null) {
            return null;
        }

        if (Str::contains($version, 'MariaDB', ignoreCase: true)) {
            return version_compare($numeric, self::MIN_MARIADB, '>=')
                ? null
                : __('This is MariaDB :version. Planvio is tested on :minimum and newer; older servers may reject some of the migrations.', ['version' => $numeric, 'minimum' => self::MIN_MARIADB]);
        }

        return version_compare($numeric, self::MIN_MYSQL, '>=')
            ? null
            : __('This is MySQL :version. Planvio needs :minimum or newer.', ['version' => $numeric, 'minimum' => self::MIN_MYSQL]);
    }

    private function tableCount(PDO $pdo, DatabaseCredentials $credentials): int
    {
        try {
            $statement = $credentials->isMysql()
                ? $pdo->query('SHOW TABLES')
                : $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");

            if ($statement === false) {
                return 0;
            }

            return count($statement->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            return 0;
        }
    }

    /* ------------------------------------------------------------------ *
     * Failure path
     * ------------------------------------------------------------------ */

    private function explain(PDOException $e, DatabaseCredentials $credentials): ConnectionTest
    {
        $code = is_array($e->errorInfo ?? null) ? (int) ($e->errorInfo[1] ?? 0) : 0;

        // A driver that is not installed at all reports through the SQLSTATE, not the
        // MySQL error number, so it is matched on the one string PDO guarantees.
        if ($code === 0 && str_contains(strtolower($e->getMessage()), 'could not find driver')) {
            return ConnectionTest::failed(
                __('This server cannot talk to MySQL: the pdo_mysql extension is not loaded.'),
                __('Enable pdo_mysql in “Select PHP Version” → Extensions and reload this page.'),
            );
        }

        return match ($code) {
            1045 => ConnectionTest::failed(
                __('The database server rejected that username or password.'),
                __('Check the user in cPanel → MySQL Databases. On cPanel the username is prefixed with your account name, for example acme_planvio. Passwords are case sensitive.'),
            ),
            1049 => ConnectionTest::failed(
                __('There is no database called “:database” on that server.', ['database' => $credentials->database]),
                __('Create it in cPanel → MySQL Databases first. The finished name includes your account prefix, for example acme_planvio.'),
            ),
            1044 => ConnectionTest::failed(
                __('The user “:username” exists but has no access to “:database”.', ['username' => $credentials->username, 'database' => $credentials->database]),
                __('In cPanel → MySQL Databases, use “Add User To Database”, then tick ALL PRIVILEGES.'),
            ),
            1130 => ConnectionTest::failed(
                __('The database server refused a connection from this server for “:username”.', ['username' => $credentials->username]),
                __('The user is only allowed to connect from certain hosts. Use 127.0.0.1 as the host, or add this server\'s address to Remote MySQL.'),
            ),
            2002 => ConnectionTest::failed(
                __('Planvio could not reach a database server at :host:port.', ['host' => $credentials->host, 'port' => $credentials->port]),
                __('Use 127.0.0.1 rather than localhost on most cPanel hosts, and check that the port is 3306 unless your host told you otherwise.'),
            ),
            2005 => ConnectionTest::failed(
                __('The host “:host” could not be resolved.', ['host' => $credentials->host]),
                __('Check the spelling. If the database is on this same server, use 127.0.0.1.'),
            ),
            2006, 2013 => ConnectionTest::failed(
                __('The database server closed the connection before Planvio finished connecting.'),
                __('This usually means the server is overloaded or restarting. Wait a moment and try again.'),
            ),
            1203, 1226, 1040 => ConnectionTest::failed(
                __('The database server has no connections left for this account.'),
                __('Too many connections are open. Wait a minute and try again, or ask your host to raise the limit.'),
            ),
            default => $this->unexpected($e),
        };
    }

    /**
     * Anything the mapping above does not recognise.
     *
     * The driver's text is logged rather than shown. It is written for a developer reading a
     * terminal, and on a failed connection it frequently contains the DSN — host, database
     * and user — which is not something to render into a page that may be screenshotted into
     * a support ticket.
     */
    private function unexpected(Throwable $e): ConnectionTest
    {
        $reference = strtoupper(Str::random(8));

        Log::error('Installer database connection failed.', [
            'reference' => $reference,
            'exception' => $e::class,
            'code' => $e->getCode(),
        ]);

        return ConnectionTest::failed(
            __('The database server answered with an error Planvio does not recognise.'),
            __('Double-check the host, database name, username and password. The technical detail was written to storage/logs.'),
            $reference,
        );
    }
}
