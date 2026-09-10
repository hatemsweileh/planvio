<?php

declare(strict_types=1);

namespace App\Services\Install;

use InvalidArgumentException;

/**
 * A dotenv document that can be edited in place.
 *
 * The installer does not generate `.env` from scratch. It starts from the `.env.example`
 * shipped in the release and overwrites the values it was given, which is why this class
 * works on lines rather than on a key/value map: every comment in that file is documentation
 * an administrator will read later, and a writer that rebuilt the file from a map would throw
 * all of it away on the first install.
 *
 * ### Quoting
 *
 * Values are written in the narrowest form that survives a round trip through phpdotenv:
 *
 * - bare, when the value contains only characters the parser reads unquoted;
 * - single quoted, when it contains anything else but no apostrophe — the parser performs no
 *   escaping and no `${VAR}` interpolation inside single quotes, so a password full of `$`
 *   and `#` lands verbatim;
 * - double quoted with `\`, `"` and `$` escaped, for the remaining case.
 *
 * A value carrying a newline or another control character is refused rather than written: the
 * file format cannot represent it, and silently truncating a password at the newline would
 * produce an installation nobody can sign in to.
 */
final class EnvFile
{
    /**
     * Characters that need no quoting at all. Deliberately conservative.
     */
    private const BARE = '/^[A-Za-z0-9_.\/:@+-]+$/';

    /**
     * @param list<string> $lines
     */
    private function __construct(private array $lines) {}

    public static function fromString(string $contents): self
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $contents);

        return new self($normalised === '' ? [] : explode("\n", $normalised));
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            return new self([]);
        }

        $contents = file_get_contents($path);

        return self::fromString($contents === false ? '' : $contents);
    }

    /**
     * The raw, still-quoted value as written in the file, or null when the key is absent.
     */
    public function raw(string $key): ?string
    {
        foreach ($this->lines as $line) {
            if ($this->keyOf($line) === $key) {
                $value = substr($line, strpos($line, '=') + 1);

                return trim($value);
            }
        }

        return null;
    }

    /**
     * The unquoted value, or null when the key is absent or empty.
     */
    public function get(string $key): ?string
    {
        $raw = $this->raw($key);

        if ($raw === null) {
            return null;
        }

        $value = $this->unquote($raw);

        return $value === '' ? null : $value;
    }

    public function has(string $key): bool
    {
        return $this->raw($key) !== null;
    }

    /**
     * Set a key, replacing the first assignment of it and leaving every comment intact.
     *
     * A key the file does not mention is appended under a trailing section header so the
     * additions an installation made are visibly separate from the shipped template.
     */
    public function set(string $key, string $value): self
    {
        $this->assertKey($key);

        $line = $key.'='.$this->quote($value);

        foreach ($this->lines as $index => $existing) {
            if ($this->keyOf($existing) === $key) {
                $this->lines[$index] = $line;

                return $this;
            }
        }

        $this->lines[] = $line;

        return $this;
    }

    /**
     * @param array<string, string> $values
     */
    public function fill(array $values): self
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return $this;
    }

    public function render(): string
    {
        $body = implode("\n", $this->lines);

        return rtrim($body, "\n")."\n";
    }

    /**
     * The assignment key on a line, or null when the line is a comment, blank or malformed.
     */
    private function keyOf(string $line): ?string
    {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            return null;
        }

        if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $trimmed, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function quote(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Environment values cannot contain control characters.');
        }

        if (preg_match(self::BARE, $value) === 1) {
            return $value;
        }

        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\"', '\$'], $value).'"';
    }

    private function unquote(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        if (str_starts_with($raw, "'") && str_ends_with($raw, "'") && strlen($raw) >= 2) {
            return substr($raw, 1, -1);
        }

        if (str_starts_with($raw, '"') && str_ends_with($raw, '"') && strlen($raw) >= 2) {
            return str_replace(['\\"', '\\$', '\\\\'], ['"', '$', '\\'], substr($raw, 1, -1));
        }

        // An unquoted value ends at the first inline comment.
        $hash = strpos($raw, ' #');

        return rtrim($hash === false ? $raw : substr($raw, 0, $hash));
    }

    private function assertKey(string $key): void
    {
        if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid environment key [{$key}].");
        }
    }
}
