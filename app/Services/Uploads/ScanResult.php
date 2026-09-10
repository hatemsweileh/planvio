<?php

declare(strict_types=1);

namespace App\Services\Uploads;

/**
 * What a scanner concluded about one file.
 *
 * Three outcomes, not two. "Clean" and "infected" are the obvious pair; the third —
 * *unavailable* — is the one that decides whether the seam is worth having.
 *
 * A scanner that has been configured and cannot be reached has not found the file clean.
 * It has found nothing at all. Treating that as a pass would mean the control silently
 * stops existing the first time the daemon is restarted, the socket path changes, or a
 * container is rescheduled — and nobody would know, because uploads would keep working.
 * So an unavailable scanner refuses the upload, and {@see self::$detail} says why in words
 * an administrator can act on.
 *
 * The distinction matters in the other direction too: an infected file is the uploader's
 * problem and an unreachable daemon is the administrator's, and telling one of them the
 * other's news helps nobody.
 */
final readonly class ScanResult
{
    private function __construct(
        public bool $clean,
        public bool $available,
        /** The malware name the scanner reported, when it reported one. */
        public ?string $signature = null,
        /** Why the scanner could not answer. Never shown to the uploader verbatim. */
        public ?string $detail = null,
    ) {}

    public static function clean(): self
    {
        return new self(clean: true, available: true);
    }

    public static function infected(string $signature): self
    {
        return new self(clean: false, available: true, signature: $signature);
    }

    /**
     * The scanner could not be reached, could not read the file, or answered with something
     * that is not a verdict.
     */
    public static function unavailable(string $detail): self
    {
        return new self(clean: false, available: false, detail: $detail);
    }

    /**
     * True only when a scanner actually ran and actually passed the file.
     */
    public function passed(): bool
    {
        return $this->clean && $this->available;
    }
}
