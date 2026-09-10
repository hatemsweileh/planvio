<?php

declare(strict_types=1);

namespace App\Notifications\Ai;

use App\Livewire\App\Ai\Support\ToolTrace;
use App\Models\AiRun;
use App\Models\AiToolRun;
use App\Notifications\PlanvioNotification;
use App\Notifications\Support\PlanvioUrl;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The agent changed something.
 *
 * This is the notification that makes autonomy acceptable: a person who did not press the
 * button still finds out, in their inbox, that work was created, moved or closed on their
 * behalf, with a link straight to the audit trail (ARCHITECTURE.md §7.1).
 *
 * The tool's *arguments* are never carried, even though `ai_tool_runs.arguments` is stored
 * redacted. A notification payload is copied into mail, into browser storage and into whatever
 * an inbox row is rendered by; the summary the tool produced says what happened without any
 * of that reach.
 */
final class AiActionExecuted extends PlanvioNotification
{
    public function __construct(
        private readonly AiRun $run,
        private readonly AiToolRun $toolRun,
    ) {}

    public function category(): string
    {
        return 'ai.action_executed';
    }

    public function workspaceId(): ?int
    {
        return (int) $this->run->workspace_id;
    }

    public function projectId(): ?int
    {
        $projectId = $this->toolRun->project_id ?? $this->run->project_id;

        return $projectId === null ? null : (int) $projectId;
    }

    public function isAi(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $run = $this->context();

        return $this->payload(
            title: __('The assistant ran :tool', ['tool' => $this->toolLabel()]),
            body: $this->summary(),
            url: PlanvioUrl::aiRun($run->workspace, (string) $run->uuid),
            actor: null,
            subjectType: $this->toolRun->subject_type,
            subjectId: $this->toolRun->subject_id === null ? null : (int) $this->toolRun->subject_id,
            extra: [
                'ai_run_id' => (int) $run->getKey(),
                'ai_run_uuid' => (string) $run->uuid,
                'ai_tool_run_id' => (int) $this->toolRun->getKey(),
                'tool' => (string) $this->toolRun->tool,
                'risk' => $this->toolRun->risk->value,
                'mode' => $run->mode->value,
                'trigger' => $run->trigger->value,
                'result_summary' => $this->toolRun->result_summary,
                'approved_by' => $this->toolRun->approved_by === null ? null : (int) $this->toolRun->approved_by,
            ],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $run = $this->context();

        return $this->planvioMail(
            subject: __('The assistant ran :tool', ['tool' => $this->toolLabel()]),
            title: __('The assistant made a change'),
            intro: [$this->summary()],
            meta: [
                ['label' => __('Action'), 'value' => $this->toolLabel()],
                ['label' => __('Risk'), 'value' => $this->toolRun->risk->label()],
                ['label' => __('Mode'), 'value' => $run->mode->label()],
                ['label' => __('Started by'), 'value' => (string) ($run->user?->name ?? __('An automation'))],
            ],
            actionText: __('See what the assistant did'),
            actionUrl: PlanvioUrl::aiRun($run->workspace, (string) $run->uuid),
            outro: [__('Every step of this run is recorded and can be reviewed in full.')],
        );
    }

    /**
     * `create_task` reads as "Create task" to a person who never sees a tool registry — and
     * as "إنشاء مهمة" to one reading in Arabic.
     *
     * Through {@see ToolTrace} rather than a local `str_replace`, which is what the run
     * transcript uses: the catalogue has a name for every tool under `ai.tools.names`, and
     * spelling the identifier out here instead put an English phrase in the middle of an
     * otherwise Arabic notification.
     */
    private function toolLabel(): string
    {
        return ToolTrace::humanise((string) $this->toolRun->tool);
    }

    private function summary(): string
    {
        $summary = $this->toolRun->result_summary;

        if (is_string($summary) && trim($summary) !== '') {
            return $summary;
        }

        return __('The assistant completed :tool.', ['tool' => $this->toolLabel()]);
    }

    private function context(): AiRun
    {
        return $this->run->loadMissing(['workspace', 'user']);
    }
}
