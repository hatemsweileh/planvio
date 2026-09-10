<?php

declare(strict_types=1);

namespace App\Livewire\App\Ai\Support;

use App\Enums\AiToolRisk;
use App\Enums\ToolRunStatus;
use App\Models\AiToolRun;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * One line of the tool trace, ready to render.
 *
 * The trace is the product's credibility. A person has to be able to read, without opening
 * a log or a JSON blob, exactly what the agent did on their behalf: which tool, against
 * what, what came back, how long it took and whether it worked. Every value here comes from
 * an `ai_tool_runs` row — the audit spine — rather than from anything the model said about
 * itself, which is the difference between a trace and a transcript.
 *
 * Two rules keep it honest.
 *
 * **Nothing is invented.** A call whose row cannot be matched still renders, from the
 * assistant message's own proposal, with no status. A trace with a gap in it is worse than
 * useless — it teaches people the list is not the whole list.
 *
 * **Arguments are summarised, never editorialised.** {@see self::summarise()} walks the
 * stored arguments in the order they were recorded and renders each as `key: value`. It
 * drops nothing but empties and it does not reorder, so what the line says is what the row
 * holds. The full, verbatim arguments are shown in the approval card, which is the one
 * place a person is being asked to authorise them.
 */
final readonly class ToolTrace
{
    /** Arguments beyond this many are counted rather than listed, so the line stays one line. */
    private const MAX_PAIRS = 4;

    /** A single value longer than this is elided; the approval card shows it in full. */
    private const MAX_VALUE_CHARS = 72;

    /** What {@see self::summarise()} joins pairs with, and what {@see self::argumentPairs()} splits on. */
    private const PAIR_SEPARATOR = ' · ';

    public function __construct(
        public ?int $toolRunId,
        public string $tool,
        public string $label,
        public string $arguments,
        public ?string $result,
        public ?string $duration,
        public ?ToolRunStatus $status,
        public AiToolRisk $risk,
        public ?string $error,
    ) {}

    /**
     * The same summary, as the pairs it is made of.
     *
     * The view needs them separately rather than joined, because each pair has to be its own
     * bidi isolate: `key: value` runs are Latin keys against values in whatever language the
     * caller wrote, and unisolated the algorithm reorders the pairs against one another
     * inside an Arabic page — the arguments then read in an order nobody passed.
     *
     * @return list<string>
     */
    public function argumentPairs(): array
    {
        return $this->arguments === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(self::PAIR_SEPARATOR, $this->arguments))));
    }

    /**
     * The recorded call: what actually happened.
     */
    public static function fromToolRun(AiToolRun $row): self
    {
        return new self(
            toolRunId: (int) $row->getKey(),
            tool: (string) $row->tool,
            label: self::humanise((string) $row->tool),
            arguments: self::summarise(is_array($row->arguments) ? $row->arguments : []),
            result: self::text($row->result_summary),
            duration: self::duration($row->duration_ms),
            status: $row->status,
            risk: $row->risk ?? AiToolRisk::Read,
            error: self::text($row->error),
        );
    }

    /**
     * A call the model proposed for which no audit row could be matched.
     *
     * It renders with no status rather than being hidden: the assistant turn says the call
     * was asked for, and silently dropping it would make the trace a summary instead of a
     * record.
     *
     * @param array<array-key, mixed> $call as stored on `ai_messages.tool_calls`
     */
    public static function fromProposal(array $call): self
    {
        $tool = is_string($call['name'] ?? null) ? $call['name'] : '';
        $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];

        return new self(
            toolRunId: null,
            tool: $tool,
            label: self::humanise($tool),
            arguments: self::summarise($arguments),
            result: null,
            duration: null,
            status: null,
            risk: AiToolRisk::Read,
            error: null,
        );
    }

    /* ------------------------------------------------------------------ *
     * Presentation
     * ------------------------------------------------------------------ */

    /**
     * The status dot's colour, in the same ten-name palette every badge uses.
     */
    public function tone(): string
    {
        return $this->status?->color() ?? 'gray';
    }

    public function statusLabel(): string
    {
        return $this->status?->label() ?? __('Not recorded');
    }

    public function awaitingDecision(): bool
    {
        return $this->status?->isAwaitingDecision() === true;
    }

    public function failed(): bool
    {
        return $this->status === ToolRunStatus::Failed;
    }

    /**
     * Whether this call changed something, or would have.
     */
    public function isMutating(): bool
    {
        return $this->risk !== AiToolRisk::Read;
    }

    /* ------------------------------------------------------------------ *
     * Formatting
     * ------------------------------------------------------------------ */

    /**
     * The tool's name as a sentence can contain it.
     *
     * This label is set inside prose — "Running :tool.", the heading of an approval card — so
     * in Arabic a mechanical `Create task` would be an English phrase in an Arabic sentence.
     * `lang/<locale>/ai.php` carries one line per tool for that; the English lines are the
     * mechanical result, so nothing on an English installation changes.
     *
     * A tool with no line falls back to the mechanical form rather than to its key, which is
     * what keeps a newly added tool readable before anybody has catalogued it. The snake_case
     * name is shown beside this everywhere it appears, so the identifier is never lost.
     */
    public static function humanise(string $tool): string
    {
        $words = trim(str_replace('_', ' ', $tool));

        if ($words === '') {
            return __('Unknown tool');
        }

        $key = 'ai.tools.names.'.$tool;

        return Lang::has($key) ? (string) __($key) : Str::ucfirst($words);
    }

    /**
     * The arguments as one readable line — no braces, no quotes, no JSON.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function summarise(array $arguments): string
    {
        $pairs = [];
        $skipped = 0;

        foreach ($arguments as $key => $value) {
            $rendered = self::value($value);

            if ($rendered === null) {
                continue;
            }

            if (count($pairs) >= self::MAX_PAIRS) {
                $skipped++;

                continue;
            }

            $pairs[] = str_replace('_', ' ', (string) $key).': '.$rendered;
        }

        if ($pairs === []) {
            return __('no arguments');
        }

        if ($skipped > 0) {
            $pairs[] = trans_choice(
                '{1}and :count more field|[2,*]and :count more fields',
                $skipped,
                ['count' => $skipped],
            );
        }

        return implode(self::PAIR_SEPARATOR, $pairs);
    }

    /**
     * Sub-second calls read as milliseconds, everything else as seconds to one decimal.
     * "0.0s" tells a reader nothing; "84 ms" tells them the call never left the box.
     */
    public static function duration(?int $milliseconds): ?string
    {
        if ($milliseconds === null || $milliseconds < 0) {
            return null;
        }

        if ($milliseconds < 1000) {
            return __(':ms ms', ['ms' => $milliseconds]);
        }

        return __(':seconds s', ['seconds' => number_format($milliseconds / 1000, 1)]);
    }

    private static function value(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? __('yes') : __('no'),
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => self::string($value),
            is_array($value) => $value === []
                ? null
                : trans_choice('{1}:count item|[2,*]:count items', count($value), ['count' => count($value)]),
            default => null,
        };
    }

    private static function string(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $trimmed);

        return Str::limit(is_string($collapsed) ? $collapsed : $trimmed, self::MAX_VALUE_CHARS);
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
