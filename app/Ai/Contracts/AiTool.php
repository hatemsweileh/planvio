<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Enums\AiToolRisk;
use App\Enums\Permission;

/**
 * One capability the model may invoke (ARCHITECTURE.md section 7.2).
 *
 * A tool is the ONLY way the AI reaches the database, and it reaches it only through
 * `App\Actions\*` executed inside the acting user's authority. No implementation may build a
 * query from model output, touch the filesystem, run a process, or instantiate a class named
 * by the model.
 *
 * The declarations here are what the agent loop gates on, in this order: registry resolution
 * by {@see self::name()}, argument validation against {@see self::parameters()},
 * policy allow/deny, `Gate::forUser($actingUser)` against {@see self::permission()}, workspace
 * scope assertion, then the risk and approval gate on {@see self::risk()}.
 */
interface AiTool
{
    /**
     * snake_case, unique, and present in the fixed registry (ARCHITECTURE.md section 7.4).
     */
    public function name(): string;

    /**
     * Coarse grouping used when presenting the tool set to the model and to administrators.
     */
    public function group(): string;

    /**
     * Shown to the model. Describe what the tool does and what it will refuse, in a sentence.
     */
    public function description(): string;

    /**
     * A JSON Schema object describing the arguments. Validated before execution; extra or
     * malformed arguments are rejected rather than ignored.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    public function risk(): AiToolRisk;

    /**
     * Checked with `Gate::forUser($actingUser)`. Null only for tools that need no permission
     * beyond membership of the bound workspace.
     */
    public function permission(): ?Permission;

    /**
     * Whether execution changes state. Mutating tools are unavailable in assistant mode, are
     * subject to approval, and compute an idempotency key.
     */
    public function isMutating(): bool;

    /**
     * @param array<string, mixed> $args validated against {@see self::parameters()}
     */
    public function execute(array $args, AgentContext $ctx): ToolResult;
}
