<?php

declare(strict_types=1);

namespace App\Ai\Context;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * A small line-oriented builder for the fact sheets the context providers emit.
 *
 * Context is written as `label: value` lines rather than prose on purpose. Prose invites the
 * model to restate an interpretation as though it were recorded data — "the project is
 * slipping" reads like a fact and cannot be checked — whereas "overdue tasks: 7" can be
 * traced back to a row. The system prompt asks the model to label its own analysis; giving it
 * analysis dressed as data would make that impossible to honour.
 *
 * Everything here also flattens: HTML descriptions are stripped and collapsed to a bounded
 * excerpt, because a wiki page's markup costs tokens and tells the model nothing, and because
 * the fewer shapes workspace text arrives in, the fewer ways it can be mistaken for structure.
 */
final class Facts
{
    /** @var list<string> */
    private array $lines = [];

    public function __construct(private readonly string $heading = '')
    {
        if ($heading !== '') {
            $this->lines[] = $heading;
        }
    }

    public static function for(string $heading = ''): self
    {
        return new self($heading);
    }

    /**
     * Add `label: value`. Null and empty values are skipped — an absent fact is better left
     * out than asserted as "none", which the model would repeat as though it were checked.
     */
    public function add(string $label, mixed $value): self
    {
        $rendered = self::render($value);

        if ($rendered !== null) {
            $this->lines[] = $label.': '.$rendered;
        }

        return $this;
    }

    /**
     * Add `label: value` even when the value is zero or empty — for counts, where "0" is
     * itself the answer.
     */
    public function count(string $label, int $value): self
    {
        $this->lines[] = $label.': '.$value;

        return $this;
    }

    public function line(string $text): self
    {
        if (trim($text) !== '') {
            $this->lines[] = $text;
        }

        return $this;
    }

    public function blank(): self
    {
        if ($this->lines !== [] && end($this->lines) !== '') {
            $this->lines[] = '';
        }

        return $this;
    }

    /**
     * A bulleted block. Omitted entirely when there is nothing to list.
     *
     * @param iterable<int, string> $items
     */
    public function bullets(string $label, iterable $items): self
    {
        $rendered = [];

        foreach ($items as $item) {
            if (trim($item) !== '') {
                $rendered[] = '- '.$item;
            }
        }

        if ($rendered === []) {
            return $this;
        }

        $this->lines[] = $label.':';

        foreach ($rendered as $line) {
            $this->lines[] = $line;
        }

        return $this;
    }

    public function isEmpty(): bool
    {
        foreach ($this->lines as $line) {
            if (trim($line) !== '' && $line !== $this->heading) {
                return false;
            }
        }

        return true;
    }

    public function toString(): string
    {
        return implode("\n", $this->lines);
    }

    /* ------------------------------------------------------------------ *
     * Value formatting
     * ------------------------------------------------------------------ */

    /**
     * Plain text from stored HTML, collapsed and truncated to
     * `config('ai.context.excerpt_chars')`.
     */
    public static function excerpt(?string $html, ?int $characters = null): ?string
    {
        if ($html === null) {
            return null;
        }

        $limit = $characters ?? self::excerptLimit();

        // Strip real markup first, then decode entities, so the model reads the text a
        // person wrote rather than its HTML encoding. Anything that decodes back into a
        // wrapper tag is re-escaped by UntrustedData when PromptBuilder wraps the fragment.
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim(is_string($text) ? $text : '');

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit - 1).'…';
    }

    /**
     * A date column rendered in the workspace timezone, so "due 2026-09-11" means the same
     * day the person reading the board sees.
     */
    public static function date(mixed $value, string $timezone): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return Carbon::instance($value)->setTimezone($timezone)->toDateString();
    }

    public static function dateTime(mixed $value, string $timezone): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return Carbon::instance($value)->setTimezone($timezone)->format('Y-m-d H:i');
    }

    public static function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    public static function minutesAsHours(?int $minutes): ?string
    {
        if ($minutes === null || $minutes <= 0) {
            return null;
        }

        return number_format($minutes / 60, 1).'h';
    }

    private static function render(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (is_bool($value)) {
            return self::yesNo($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function excerptLimit(): int
    {
        $configured = config('ai.context.excerpt_chars');

        return is_int($configured) && $configured > 0 ? $configured : 400;
    }
}
