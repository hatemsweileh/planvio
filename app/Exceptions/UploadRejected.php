<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * An upload was refused by the attachment gate.
 *
 * This is the one domain exception that is also a security boundary: every path that
 * rejects a file throws it, and `reason` names which control fired so the refusal can be
 * counted and alerted on. A burst of `blocked_extension` from one account is somebody
 * probing for a webshell, not a user picking the wrong file.
 *
 * The message shown to the uploader deliberately says what was wrong with their file and
 * nothing about where files are stored or how the check works.
 */
final class UploadRejected extends DomainException
{
    public const REASON_UPLOAD_FAILED = 'upload_failed';

    public const REASON_UNREADABLE = 'unreadable';

    public const REASON_NO_EXTENSION = 'no_extension';

    public const REASON_BLOCKED_EXTENSION = 'blocked_extension';

    public const REASON_EXTENSION_NOT_ALLOWED = 'extension_not_allowed';

    public const REASON_MIME_NOT_ALLOWED = 'mime_not_allowed';

    public const REASON_MIME_MISMATCH = 'mime_mismatch';

    public const REASON_TOO_LARGE = 'too_large';

    public const REASON_EMPTY = 'empty_file';

    public const REASON_STORE_FAILED = 'store_failed';

    public const REASON_NOT_ATTACHABLE = 'not_attachable';

    public const REASON_INFECTED = 'infected';

    public const REASON_SCANNER_UNAVAILABLE = 'scanner_unavailable';

    /**
     * @param array<string, scalar|array<array-key, mixed>|null> $context
     */
    private function __construct(
        string $message,
        public readonly string $reason,
        array $context = [],
    ) {
        parent::__construct($message, $context + ['reason' => $reason]);
    }

    public static function uploadFailed(string $originalName): self
    {
        return new self(
            __('actions.attachments.upload_failed'),
            self::REASON_UPLOAD_FAILED,
            ['original_name' => $originalName],
        );
    }

    public static function unreadable(string $originalName): self
    {
        return new self(
            __('actions.attachments.unreadable'),
            self::REASON_UNREADABLE,
            ['original_name' => $originalName],
        );
    }

    public static function withoutExtension(string $originalName): self
    {
        return new self(
            __('actions.attachments.no_extension'),
            self::REASON_NO_EXTENSION,
            ['original_name' => $originalName],
        );
    }

    public static function blockedExtension(string $extension, string $originalName): self
    {
        return new self(
            __('actions.attachments.blocked_extension', ['extension' => $extension]),
            self::REASON_BLOCKED_EXTENSION,
            ['extension' => $extension, 'original_name' => $originalName],
        );
    }

    public static function extensionNotAllowed(string $extension, string $originalName): self
    {
        return new self(
            __('actions.attachments.extension_not_allowed', ['extension' => $extension]),
            self::REASON_EXTENSION_NOT_ALLOWED,
            ['extension' => $extension, 'original_name' => $originalName],
        );
    }

    public static function mimeNotAllowed(string $mime, string $originalName): self
    {
        return new self(
            __('actions.attachments.mime_not_allowed', ['mime' => $mime]),
            self::REASON_MIME_NOT_ALLOWED,
            ['mime' => $mime, 'original_name' => $originalName],
        );
    }

    public static function mimeMismatch(string $mime, string $extension, string $originalName): self
    {
        return new self(
            __('actions.attachments.mime_mismatch', ['mime' => $mime, 'extension' => $extension]),
            self::REASON_MIME_MISMATCH,
            ['mime' => $mime, 'extension' => $extension, 'original_name' => $originalName],
        );
    }

    public static function tooLarge(int $bytes, int $limitBytes, string $originalName): self
    {
        return new self(
            __('actions.attachments.too_large', [
                'size' => self::humanBytes($bytes),
                'limit' => self::humanBytes($limitBytes),
            ]),
            self::REASON_TOO_LARGE,
            ['size_bytes' => $bytes, 'limit_bytes' => $limitBytes, 'original_name' => $originalName],
        );
    }

    public static function emptyFile(string $originalName): self
    {
        return new self(
            __('actions.attachments.empty_file'),
            self::REASON_EMPTY,
            ['original_name' => $originalName],
        );
    }

    public static function storeFailed(string $originalName): self
    {
        return new self(
            __('actions.attachments.store_failed'),
            self::REASON_STORE_FAILED,
            ['original_name' => $originalName],
        );
    }

    /**
     * The subject carries no tenant column, so the file could not be scoped to a workspace
     * and would sit outside every isolation layer (ARCHITECTURE.md §3).
     */
    public static function notAttachable(string $attachableType): self
    {
        return new self(
            __('actions.attachments.not_attachable'),
            self::REASON_NOT_ATTACHABLE,
            ['attachable_type' => $attachableType],
        );
    }

    /**
     * The configured scanner recognised the contents.
     *
     * The signature name goes into the context, not into the sentence: it is a fact for the
     * audit trail, and reading "Win.Trojan.Agent-1234567" back to somebody who has just
     * uploaded a document tells them nothing they can act on.
     */
    public static function infected(string $signature, string $originalName): self
    {
        return new self(
            __('actions.attachments.infected'),
            self::REASON_INFECTED,
            ['signature' => $signature, 'original_name' => $originalName],
        );
    }

    /**
     * A scanner is configured and did not answer, so nothing is known about the file.
     *
     * This refusal is aimed at the administrator rather than the uploader, and says so:
     * the person holding the file cannot fix a daemon, and telling them "your file may be
     * infected" would be a guess. Failing closed is the whole reason the seam exists — a
     * scanning control that quietly stops scanning is worse than none, because nobody
     * checks a control they believe is running.
     */
    public static function scannerUnavailable(string $scanner, string $detail, string $originalName): self
    {
        return new self(
            __('actions.attachments.scanner_unavailable'),
            self::REASON_SCANNER_UNAVAILABLE,
            ['scanner' => $scanner, 'detail' => $detail, 'original_name' => $originalName],
        );
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max(0, $bytes);

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return number_format($value, $value >= 100 ? 0 : 1).' '.$units[$power];
    }
}
