<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use Closure;
use Throwable;

/**
 * Streams an upload to a running `clamd` and reports what it says.
 *
 * ClamAV's `INSTREAM` command is used rather than `SCAN <path>`, for two reasons that both
 * matter on the hosting Planvio targets. `SCAN` requires the daemon to be able to open the
 * file itself, which means the upload has to live somewhere clamd's user can read — on
 * shared hosting it usually cannot read PHP's temporary directory at all. And `INSTREAM`
 * never puts a path from this process into a command the daemon parses.
 *
 * The wire format, which is all of it:
 *
 * ```
 * ->  zINSTREAM\0
 * ->  <uint32 be length><chunk>   repeated
 * ->  <uint32 be 0>               end of stream
 * <-  stream: OK\0
 * <-  stream: <Signature> FOUND\0
 * <-  stream: <reason> ERROR\0
 * ```
 *
 * ## Fail closed, and say which failure it was
 *
 * Nothing here throws and nothing here returns {@see ScanResult::clean()} unless clamd
 * actually answered `OK`. A refused connection, a timeout, a half-written stream, a
 * response that is not one of the three shapes above — each is
 * {@see ScanResult::unavailable()} carrying the reason, and the caller refuses the upload.
 *
 * That is the whole point of configuring a scanner. An installation that turned this on
 * and then let uploads through unscanned because the daemon was restarting would have a
 * control that is present in the config file and absent in fact, which is worse than not
 * having one — the administrator would stop watching.
 *
 * ## Timeouts
 *
 * `timeout` bounds connecting *and* every read: clamd queues concurrent scans, so a busy
 * daemon can accept the connection immediately and take seconds to answer. Writes are
 * bounded by the same value through the stream's own timeout.
 */
final class ClamAvScanner implements ScansUploads
{
    /**
     * Bytes per INSTREAM chunk.
     *
     * Comfortably under clamd's `StreamMaxLength` chunk handling and large enough that a
     * 20 MB attachment is a few hundred writes rather than a few thousand.
     */
    private const CHUNK_BYTES = 65536;

    /** Longest response clamd will send. A verdict is a short line; anything longer is noise. */
    private const MAX_RESPONSE_BYTES = 4096;

    /**
     * @param string $host clamd's TCP host, used when $socket is not set
     * @param int $port clamd's TCP port
     * @param string|null $socket absolute path to clamd's unix socket; wins over host/port
     * @param int $timeout seconds allowed for connecting and for each read
     * @param int $maxBytes clamd's own StreamMaxLength; a larger file is refused unscanned
     * @param (Closure(): mixed)|null $connector opens one connection to clamd. The two
     *                                           transports above are the production cases; the suite substitutes a socket pair
     *                                           so the protocol above can be exercised without a daemon.
     */
    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 3310,
        private readonly ?string $socket = null,
        private readonly int $timeout = 30,
        private readonly int $maxBytes = 26214400,
        private readonly ?Closure $connector = null,
    ) {}

    public function name(): string
    {
        return 'clamav';
    }

    public function scan(string $path): ScanResult
    {
        $size = @filesize($path);

        if ($size === false || ! is_readable($path)) {
            return ScanResult::unavailable('the file could not be read for scanning');
        }

        if ($this->maxBytes > 0 && $size > $this->maxBytes) {
            // clamd answers an oversized stream by closing the connection, which is
            // indistinguishable from the daemon dying. Refusing here names the real cause.
            return ScanResult::unavailable(sprintf(
                'the file is larger than the scanner accepts (%d bytes, limit %d)',
                $size,
                $this->maxBytes,
            ));
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return ScanResult::unavailable('the file could not be opened for scanning');
        }

        try {
            $connection = $this->connect();
        } catch (Throwable $failure) {
            fclose($handle);

            return ScanResult::unavailable($failure->getMessage());
        }

        if (! is_resource($connection)) {
            fclose($handle);

            return ScanResult::unavailable('the scanner did not accept a connection at '.$this->target());
        }

        try {
            return $this->converse($connection, $handle);
        } catch (Throwable $failure) {
            return ScanResult::unavailable($failure->getMessage());
        } finally {
            fclose($handle);
            @fclose($connection);
        }
    }

    /* ------------------------------------------------------------------ *
     * The exchange
     * ------------------------------------------------------------------ */

    /**
     * @param resource $connection
     * @param resource $handle
     */
    private function converse(mixed $connection, mixed $handle): ScanResult
    {
        stream_set_timeout($connection, max(1, $this->timeout));

        if (! $this->write($connection, "zINSTREAM\0")) {
            return ScanResult::unavailable('the scanner closed the connection before the file was sent');
        }

        while (! feof($handle)) {
            $chunk = fread($handle, self::CHUNK_BYTES);

            if ($chunk === false) {
                return ScanResult::unavailable('the file could not be read for scanning');
            }

            if ($chunk === '') {
                continue;
            }

            if (! $this->write($connection, pack('N', strlen($chunk)).$chunk)) {
                return ScanResult::unavailable('the scanner closed the connection before the file was sent');
            }
        }

        if (! $this->write($connection, pack('N', 0))) {
            return ScanResult::unavailable('the scanner closed the connection before the file was sent');
        }

        return self::verdict($this->read($connection));
    }

    /**
     * clamd's answer, reduced to a result.
     *
     * The response is matched from the end rather than parsed as a whole, because the
     * stream name clamd echoes back is not fixed and a signature name may contain spaces.
     */
    private static function verdict(?string $response): ScanResult
    {
        if ($response === null) {
            return ScanResult::unavailable('the scanner did not answer in time');
        }

        $line = trim(str_replace("\0", '', $response));

        if ($line === '') {
            return ScanResult::unavailable('the scanner answered with nothing');
        }

        if (str_ends_with($line, 'OK')) {
            return ScanResult::clean();
        }

        if (str_ends_with($line, 'FOUND')) {
            // "stream: Eicar-Test-Signature FOUND"
            $signature = trim(substr($line, 0, -strlen('FOUND')));
            $colon = strpos($signature, ':');

            if ($colon !== false) {
                $signature = trim(substr($signature, $colon + 1));
            }

            return ScanResult::infected($signature === '' ? 'unnamed signature' : $signature);
        }

        // ERROR, or anything at all that is not one of the two verdicts above.
        return ScanResult::unavailable('the scanner reported: '.mb_substr($line, 0, 200));
    }

    /* ------------------------------------------------------------------ *
     * Transport
     * ------------------------------------------------------------------ */

    /**
     * @return resource|false
     */
    private function connect(): mixed
    {
        if ($this->connector !== null) {
            return ($this->connector)();
        }

        $errorNumber = 0;
        $errorMessage = '';

        // Suppressed and handled: a refused connection emits a warning that Laravel's error
        // handler turns into an exception, which would unwind past the fail-closed result
        // this method exists to produce.
        return @stream_socket_client(
            $this->target(),
            $errorNumber,
            $errorMessage,
            max(1, $this->timeout),
            STREAM_CLIENT_CONNECT,
        );
    }

    /**
     * The DSN, and the string an administrator reads in the refusal.
     */
    private function target(): string
    {
        return $this->socket !== null && $this->socket !== ''
            ? 'unix://'.$this->socket
            : 'tcp://'.$this->host.':'.$this->port;
    }

    /**
     * @param resource $connection
     */
    private function write(mixed $connection, string $payload): bool
    {
        $remaining = strlen($payload);

        while ($remaining > 0) {
            $written = @fwrite($connection, substr($payload, -$remaining));

            // false is a broken pipe; 0 is a socket that accepted nothing, which after a
            // timeout means the same thing for this exchange.
            if ($written === false || $written === 0) {
                return false;
            }

            $remaining -= $written;
        }

        return true;
    }

    /**
     * Read until clamd terminates its answer, or until the stream times out.
     *
     * @param resource $connection
     */
    private function read(mixed $connection): ?string
    {
        $response = '';

        while (strlen($response) < self::MAX_RESPONSE_BYTES) {
            $chunk = @fread($connection, 1024);

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($connection);

                if (($meta['timed_out'] ?? false) === true) {
                    return null;
                }

                break;
            }

            $response .= $chunk;

            if (str_contains($response, "\0")) {
                break;
            }
        }

        return $response === '' ? null : $response;
    }
}
