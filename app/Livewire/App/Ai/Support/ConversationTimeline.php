<?php

declare(strict_types=1);

namespace App\Livewire\App\Ai\Support;

use App\Enums\AiMessageRole;
use App\Models\AiMessage;
use App\Models\AiToolRun;

/**
 * Interleaves the transcript with the audit trail.
 *
 * `ai_messages` records what was said; `ai_tool_runs` records what was done. Neither alone
 * is the conversation. A reader who sees only the messages is told the assistant "created
 * the task" and has to believe it; a reader who sees only the tool runs has no idea what
 * was asked. This walks the messages in order and, at the point where the assistant
 * proposed calls, splices in the recorded row for each one.
 *
 * ## Matching, and why it is by name in order
 *
 * `ai_tool_runs` carries no `tool_call_id` — it is keyed by `(ai_run_id, sequence)`, which
 * is execution order. So each proposed call is matched against the next unconsumed row of
 * the same run with the same tool name. Within one run the model's proposal order and the
 * runner's execution order are the same list, so this is exact; where it cannot be (a row
 * the runner never got to, because an earlier call parked the run for approval) the call
 * still renders from the proposal itself.
 *
 * ## Nothing is dropped
 *
 * Rows that were never matched — a run whose assistant turn was not persisted, an approval
 * resumed in a later run — are appended in execution order rather than discarded. A trace
 * that quietly omits a call is a trace nobody should trust.
 *
 * System messages are not shown: they are Planvio's own instructions, not part of anyone's
 * conversation. Tool-result messages are not shown either — the result is already on the
 * trace line, and rendering the raw JSON twice would bury the readable version.
 */
final class ConversationTimeline
{
    /**
     * @param iterable<AiMessage> $messages chronological
     * @param iterable<AiToolRun> $toolRuns in execution order within each run
     * @return list<TimelineEntry>
     */
    public static function build(iterable $messages, iterable $toolRuns): array
    {
        $pending = self::queues($toolRuns);
        $timeline = [];

        foreach ($messages as $message) {
            $role = $message->role;

            if ($role === AiMessageRole::User) {
                $timeline[] = TimelineEntry::user($message);

                continue;
            }

            if ($role !== AiMessageRole::Assistant) {
                continue;
            }

            if (trim((string) $message->content) !== '') {
                $timeline[] = TimelineEntry::assistant($message);
            }

            if (! $message->hasToolCalls()) {
                continue;
            }

            foreach ((array) $message->tool_calls as $call) {
                if (! is_array($call)) {
                    continue;
                }

                $row = self::take($pending, $message->ai_run_id, is_string($call['name'] ?? null) ? $call['name'] : '');

                $timeline[] = $row instanceof AiToolRun
                    ? TimelineEntry::trace(ToolTrace::fromToolRun($row), $row)
                    : TimelineEntry::trace(ToolTrace::fromProposal($call));
            }
        }

        foreach ($pending as $rows) {
            foreach ($rows as $row) {
                $timeline[] = TimelineEntry::trace(ToolTrace::fromToolRun($row), $row);
            }
        }

        return $timeline;
    }

    /**
     * Tool runs grouped by run, in execution order, ready to be consumed from the front.
     *
     * @param iterable<AiToolRun> $toolRuns
     * @return array<int, list<AiToolRun>>
     */
    private static function queues(iterable $toolRuns): array
    {
        $queues = [];

        foreach ($toolRuns as $row) {
            $runId = $row->ai_run_id;

            if ($runId === null) {
                continue;
            }

            $queues[(int) $runId][] = $row;
        }

        return $queues;
    }

    /**
     * The next unconsumed row of $runId for $tool, removed from the queue.
     *
     * @param array<int, list<AiToolRun>> $pending
     */
    private static function take(array &$pending, ?int $runId, string $tool): ?AiToolRun
    {
        if ($runId === null || $tool === '' || ! isset($pending[$runId])) {
            return null;
        }

        foreach ($pending[$runId] as $index => $row) {
            if ((string) $row->tool !== $tool) {
                continue;
            }

            unset($pending[$runId][$index]);

            if ($pending[$runId] === []) {
                unset($pending[$runId]);
            }

            return $row;
        }

        return null;
    }
}
