<?php

declare(strict_types=1);

namespace App\Ai\Tools\Read;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolRegistry;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Enums\AiToolRisk;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Services\SearchResult;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The shared spine of every read tool (ARCHITECTURE.md section 7.2, AI_SECURITY
 * "Tool authorization").
 *
 * A read tool answers a question about the bound workspace. It changes nothing, so its risk
 * is fixed at {@see AiToolRisk::Read} and {@see isMutating()} is fixed at false — both
 * `final` here, because a read tool that could declare otherwise would slip past the
 * assistant-mode filter in {@see ToolRegistry::forContext()}.
 *
 * ## The fixed order
 *
 * {@see execute()} is final and performs the first two steps for every subclass, in this
 * order and no other:
 *
 *   1. validate the arguments against the tool's own `parameters()` schema — extras and
 *      malformed values are rejected, never ignored;
 *   2. bind the run's workspace, so the tenant scope is active for everything that follows.
 *
 * The subclass then does the rest inside {@see read()}: resolve the subject *within* the
 * bound workspace (never `findOrFail()` on an id the model produced), assert it is in the
 * workspace, ask the Gate as the acting user, read through a service or a scoped query, and
 * return a compact factual result.
 *
 * ## Why the helpers here are query-side, not SQL-side
 *
 * Nothing below builds SQL from model output. Identifiers arrive as validated integers or
 * as key-shaped strings matched by an anchored pattern, and every one of them ends up as a
 * bound parameter inside a scope that is already narrowed to the workspace and to the
 * projects the acting user may see. {@see visibleProjects()} is the single definition of
 * that visibility, reused by every tool so there is one rule rather than twelve.
 *
 * ## Why results are capped and the cap is announced
 *
 * A tool that quietly returns the first fifty of four hundred tasks teaches the model that
 * it has seen everything. Every list result therefore carries `total`, `returned` and
 * `omitted`, and {@see fit()} shrinks the list further — still announcing it — if the
 * encoded payload would exceed `config('ai.limits.max_tool_result_chars')`.
 */
abstract class ReadTool implements AiTool
{
    /**
     * How many rows the text-search service is asked for before structured filters narrow
     * it. Its own ceiling is 100; asking for that much and reporting when we hit it is more
     * honest than silently searching a smaller slice.
     */
    protected const TEXT_SEARCH_CANDIDATES = 100;

    final public function group(): string
    {
        return 'read';
    }

    final public function risk(): AiToolRisk
    {
        return AiToolRisk::Read;
    }

    final public function isMutating(): bool
    {
        return false;
    }

    /**
     * Validate, bind the workspace, delegate. Subclasses override {@see read()}, never this.
     *
     * @param array<string, mixed> $args
     */
    final public function execute(array $args, AgentContext $ctx): ToolResult
    {
        [$errors, $clean] = self::check($args, $this->parameters());

        if ($errors !== []) {
            return ToolResult::failed(
                __('ai.tools.invalid_arguments', [
                    'tool' => $this->name(),
                    'reason' => implode('; ', $errors),
                ]),
                'invalid_arguments',
                ['errors' => $errors],
            );
        }

        return $ctx->bindWorkspace(fn (): ToolResult => $this->read($clean, $ctx));
    }

    /**
     * @param array<string, mixed> $args validated, defaulted and coerced
     */
    abstract protected function read(array $args, AgentContext $ctx): ToolResult;

    /* ------------------------------------------------------------------ *
     * Subject resolution — always inside the workspace, never a bare find
     * ------------------------------------------------------------------ */

    /**
     * Projects the acting user may see in the bound workspace.
     *
     * Two independent narrowings, as ARCHITECTURE.md section 3 requires: the explicit
     * workspace constraint, and the membership rule that lets a guest see only the projects
     * they were added to. The global tenant scope is a third layer underneath.
     *
     * @return Builder<Project>
     */
    protected function visibleProjects(AgentContext $ctx): Builder
    {
        return Project::query()
            ->forWorkspace($ctx->workspace)
            ->visibleTo($ctx->user);
    }

    /**
     * The same set as a subquery. `toBase()` bakes the tenant and soft-delete scopes in;
     * `getQuery()` would silently drop both.
     */
    protected function visibleProjectIds(AgentContext $ctx): QueryBuilder
    {
        return $this->visibleProjects($ctx)->select('projects.id')->toBase();
    }

    /**
     * Resolve "the project the model named" — an id or a project key such as `WEB`.
     *
     * Null means "not in this workspace, or not visible to you", and the caller reports
     * exactly that. It deliberately does not distinguish the two: confirming that an id
     * exists somewhere else is itself a leak.
     */
    protected function resolveProject(AgentContext $ctx, int|string $reference): ?Project
    {
        $query = $this->visibleProjects($ctx);
        $id = self::asId($reference);

        if ($id !== null) {
            return $query->whereKey($id)->first();
        }

        $key = mb_strtoupper(trim((string) $reference));

        if ($key === '' || preg_match('/\A[A-Z][A-Z0-9]{0,11}\z/', $key) !== 1) {
            return null;
        }

        return $query->where('projects.key', $key)->first();
    }

    /**
     * Resolve a task by id or by display key (`WEB-42`), inside the bound workspace and
     * inside the projects the acting user may see.
     */
    protected function resolveTask(AgentContext $ctx, int|string $reference): ?Task
    {
        $query = Task::query()
            ->forWorkspace($ctx->workspace)
            ->whereIn('tasks.project_id', $this->visibleProjectIds($ctx))
            ->with(['project:id,key,name', 'status:id,name,category,is_completed']);

        $id = self::asId($reference);

        if ($id !== null) {
            return $query->whereKey($id)->first();
        }

        $value = trim((string) $reference);

        if (preg_match('/\A([A-Za-z][A-Za-z0-9]{0,11})-(\d{1,9})\z/', $value, $matches) !== 1) {
            return null;
        }

        $key = mb_strtoupper($matches[1]);

        return $query
            ->where('tasks.number', (int) $matches[2])
            ->whereHas('project', static fn (Builder $project): Builder => $project->where('projects.key', $key))
            ->first();
    }

    /**
     * Resolve a milestone id inside the workspace and a visible project.
     */
    protected function resolveMilestone(AgentContext $ctx, int $id): ?Milestone
    {
        return Milestone::query()
            ->forWorkspace($ctx->workspace)
            ->whereIn('milestones.project_id', $this->visibleProjectIds($ctx))
            ->whereKey($id)
            ->first();
    }

    /* ------------------------------------------------------------------ *
     * Results
     * ------------------------------------------------------------------ */

    protected function notFound(string $subject, int|string $reference): ToolResult
    {
        return ToolResult::failed(
            __('ai.tools.not_found', [
                'subject' => $subject,
                'reference' => self::clip((string) $reference, 64),
            ]),
            'not_found',
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    protected function denied(string $key, array $data = []): ToolResult
    {
        return ToolResult::denied(__('ai.tools.denied.'.$key), $data);
    }

    /**
     * Assemble the standard list envelope: what was returned, out of how much, and how much
     * the model has not seen.
     *
     * @param list<mixed> $items already limited
     * @return array{returned: int, total: int, omitted: int, items: list<mixed>}
     */
    protected function page(array $items, int $total, string $key = 'items'): array
    {
        $returned = count($items);

        return [
            'returned' => $returned,
            'total' => $total,
            'omitted' => max(0, $total - $returned),
            $key => array_values($items),
        ];
    }

    /**
     * Shrink $data until its encoded form fits the tool-result budget, dropping rows from
     * the end of $listKey and keeping `returned`/`omitted` truthful as it goes.
     *
     * Doing it here rather than leaving it to {@see ToolResult::forModel()} matters: that
     * fallback drops the whole `data` payload, which would leave the model with a summary
     * and nothing to reason over.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function fit(array $data, string $listKey): array
    {
        $budget = self::maxResultChars();

        while (self::encodedLength($data) > $budget) {
            $list = $data[$listKey] ?? null;

            if (! is_array($list) || $list === []) {
                break;
            }

            array_pop($list);

            $returned = count($list);
            $total = is_int($data['total'] ?? null) ? $data['total'] : $returned;

            $data[$listKey] = array_values($list);
            $data['returned'] = $returned;
            $data['omitted'] = max(0, $total - $returned);
            $data['truncated_for_size'] = true;
        }

        return $data;
    }

    /* ------------------------------------------------------------------ *
     * Bounds
     * ------------------------------------------------------------------ */

    /**
     * The number of rows to return: what the caller asked for, clamped to the configured
     * page size for this kind of list and to the hard list ceiling.
     */
    protected static function pageSize(mixed $requested, string $configKey, int $default): int
    {
        $ceiling = self::maxRows();
        $configured = config('planvio.pagination.'.$configKey);
        $fallback = is_int($configured) && $configured > 0 ? $configured : $default;

        if (! is_int($requested) || $requested < 1) {
            return max(1, min($fallback, $ceiling));
        }

        return max(1, min($requested, $ceiling));
    }

    protected static function maxRows(): int
    {
        $configured = config('planvio.pagination.list');

        return is_int($configured) && $configured > 0 ? $configured : 50;
    }

    protected static function maxResultChars(): int
    {
        $configured = config('ai.limits.max_tool_result_chars');

        return is_int($configured) && $configured > 0 ? $configured : 6000;
    }

    /* ------------------------------------------------------------------ *
     * Value shaping
     * ------------------------------------------------------------------ */

    /**
     * Sanitised HTML reduced to plain text and clipped. Descriptions, comment bodies and
     * wiki content all reach the model this way: the markup carries no meaning for it and
     * a full page of HTML would spend the whole result budget on tags.
     */
    protected static function excerpt(?string $html, int $length = 240): ?string
    {
        return SearchResult::snippet($html, $length);
    }

    /**
     * A date column as a bare `Y-m-d`, whichever shape the driver handed back.
     */
    protected static function day(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toDateString();
        }

        if (is_string($value) && $value !== '') {
            return mb_substr($value, 0, 10);
        }

        return null;
    }

    /**
     * An instant in the workspace timezone. Everything the model reasons about happens in
     * the workspace's day, never the server's.
     */
    protected static function moment(mixed $value, string $timezone): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return CarbonImmutable::instance($value)->setTimezone($timezone)->toIso8601String();
    }

    protected static function clip(string $value, int $length): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length - 1).'…';
    }

    /**
     * A positive integer identifier, or null. `0`, `-1`, `"7abc"` and `""` are all null:
     * an identifier that is not usable must not reach a query as a wildcard.
     */
    protected static function asId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/\A\d{1,15}\z/', trim($value)) === 1) {
            $id = (int) trim($value);

            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encodedLength(array $data): int
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? 0 : mb_strlen($encoded);
    }

    /* ------------------------------------------------------------------ *
     * Argument validation
     *
     * A deliberately small JSON Schema subset — the keywords the tools in this
     * namespace actually declare — implemented here rather than pulled in as a
     * dependency, because production ships with no Composer (CLAUDE.md rule 2).
     * Anything the schema does not describe is rejected: "extra or malformed
     * arguments are rejected rather than ignored" (AiTool::parameters()).
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $schema
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private static function check(array $args, array $schema): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        /** @var list<string> $errors */
        $errors = [];
        /** @var array<string, mixed> $clean */
        $clean = [];

        foreach (array_keys($args) as $key) {
            if (! array_key_exists((string) $key, $properties)) {
                $errors[] = 'unknown argument "'.self::clip((string) $key, 64).'"';
            }
        }

        foreach ($required as $name) {
            if (is_string($name) && ($args[$name] ?? null) === null) {
                $errors[] = '"'.$name.'" is required';
            }
        }

        foreach ($properties as $name => $rules) {
            if (! is_string($name) || ! is_array($rules)) {
                continue;
            }

            if (($args[$name] ?? null) === null) {
                if (array_key_exists('default', $rules)) {
                    $clean[$name] = $rules['default'];
                }

                continue;
            }

            $value = self::coerce($args[$name], $rules);
            $violation = self::violation($name, $value, $rules);

            if ($violation !== null) {
                $errors[] = $violation;

                continue;
            }

            $clean[$name] = $value;
        }

        return [$errors, $clean];
    }

    /**
     * Narrow, documented leniency: providers routinely emit `"5"` where the schema says
     * integer. Coercion is limited to unambiguous numeric and boolean literals, and only
     * when the property does not also accept a string — so `"me"` stays `"me"` and nothing
     * ever changes meaning on the way in.
     *
     * @param array<string, mixed> $rules
     */
    private static function coerce(mixed $value, array $rules): mixed
    {
        $types = self::types($rules);

        if (is_string($value)) {
            $trimmed = trim($value);

            if (in_array('string', $types, true)) {
                return $trimmed;
            }

            if (in_array('integer', $types, true) && preg_match('/\A-?\d{1,15}\z/', $trimmed) === 1) {
                return (int) $trimmed;
            }

            if (in_array('number', $types, true) && is_numeric($trimmed)) {
                return (float) $trimmed;
            }

            if (in_array('boolean', $types, true) && in_array(mb_strtolower($trimmed), ['true', 'false'], true)) {
                return mb_strtolower($trimmed) === 'true';
            }

            return $trimmed;
        }

        if (is_float($value)
            && in_array('integer', $types, true)
            && ! in_array('number', $types, true)
            && floor($value) === $value) {
            return (int) $value;
        }

        if (is_array($value) && is_array($rules['items'] ?? null)) {
            /** @var array<string, mixed> $items */
            $items = $rules['items'];

            return array_map(static fn (mixed $entry): mixed => self::coerce($entry, $items), $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $rules
     */
    private static function violation(string $name, mixed $value, array $rules): ?string
    {
        $types = self::types($rules);

        if ($types !== [] && ! self::matchesType($value, $types)) {
            return '"'.$name.'" must be '.implode(' or ', $types);
        }

        $enum = $rules['enum'] ?? null;

        // A property that accepts either one value or a list of them declares the allowed
        // set twice: once at the top level for the scalar form, once under `items` for the
        // list form. The top-level set describes the scalar only, so a list is judged by
        // `items` below rather than failed against a set it was never a member of.
        if (is_array($value) && is_array($rules['items'] ?? null)) {
            $enum = null;
        }

        if (is_array($enum) && ! in_array($value, $enum, true)) {
            return '"'.$name.'" must be one of: '.implode(', ', array_map(
                static fn (mixed $option): string => is_scalar($option) ? (string) $option : '?',
                $enum,
            ));
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            $min = $rules['minLength'] ?? null;
            $max = $rules['maxLength'] ?? null;

            if (is_int($min) && $length < $min) {
                return '"'.$name.'" must be at least '.$min.' characters';
            }

            if (is_int($max) && $length > $max) {
                return '"'.$name.'" must be at most '.$max.' characters';
            }
        }

        if (is_int($value) || is_float($value)) {
            $min = $rules['minimum'] ?? null;
            $max = $rules['maximum'] ?? null;

            if (is_int($min) && $value < $min) {
                return '"'.$name.'" must be at least '.$min;
            }

            if (is_int($max) && $value > $max) {
                return '"'.$name.'" must be at most '.$max;
            }
        }

        if (is_array($value)) {
            if (! array_is_list($value)) {
                return '"'.$name.'" must be a list';
            }

            $count = count($value);
            $min = $rules['minItems'] ?? null;
            $max = $rules['maxItems'] ?? null;

            if (is_int($min) && $count < $min) {
                return '"'.$name.'" must contain at least '.$min.' item(s)';
            }

            if (is_int($max) && $count > $max) {
                return '"'.$name.'" must contain at most '.$max.' item(s)';
            }

            $items = $rules['items'] ?? null;

            if (is_array($items)) {
                foreach ($value as $index => $entry) {
                    $violation = self::violation($name.'['.$index.']', $entry, $items);

                    if ($violation !== null) {
                        return $violation;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $types
     */
    private static function matchesType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value),
                'object' => is_array($value),
                'null' => $value === null,
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $rules
     * @return list<string>
     */
    private static function types(array $rules): array
    {
        $type = $rules['type'] ?? null;

        if (is_string($type)) {
            return [$type];
        }

        if (is_array($type)) {
            return array_values(array_filter($type, 'is_string'));
        }

        return [];
    }
}
