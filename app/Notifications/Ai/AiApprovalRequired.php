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
 * The agent stopped and is waiting for a human decision.
 *
 * The run is parked at `awaiting_approval` until somebody with `ai.approve` acts, so this
 * notification is on the critical path rather than being an FYI: nothing else moves until
 * it is read. That is also why the approval screen is the call to action rather than the
 * run transcript — the recipient's job here is to decide, not to browse.
 */
final class AiApprovalRequired extends PlanvioNotification
{
    public function __construct(
        private readonly AiRun $run,
        private readonly AiToolRun $toolRun,
    ) {}

    public function category(): string
    {
        return 'ai.approval_required';
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
            title: __('The assistant needs your approval'),
            body: __(':tool — :risk risk', [
                'tool' => $this->toolLabel(),
                'risk' => mb_strtolower($this->toolRun->risk->label()),
            ]),
            url: PlanvioUrl::aiApprovals($run->workspace),
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
                'objective' => $run->objective,
                'requested_by' => $run->user_id === null ? null : (int) $run->user_id,
                'run_url' => PlanvioUrl::aiRun($run->workspace, (string) $run->uuid),
            ],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $run = $this->context();

        $meta = [
            ['label' => __('Action'), 'value' => $this->toolLabel()],
            ['label' => __('Risk'), 'value' => $this->toolRun->risk->label()],
            ['label' => __('Mode'), 'value' => $run->mode->label()],
            ['label' => __('Requested by'), 'value' => (string) ($run->user?->name ?? __('An automation'))],
        ];

        $intro = [__('It will not go ahead until somebody approves it.')];

        $objective = $run->objective;

        if (is_string($objective) && trim($objective) !== '') {
            $intro[] = __('What it was asked to do: :objective', ['objective' => $objective]);
        }

        return $this->planvioMail(
            subject: __('Approval needed: :tool', ['tool' => $this->toolLabel()]),
            title: __('The assistant needs your approval'),
            intro: $intro,
            meta: $meta,
            actionText: __('Review and decide'),
            actionUrl: PlanvioUrl::aiApprovals($run->workspace),
            outro: [__('Rejecting is safe: the run stops and nothing is changed.')],
        );
    }

    /**
     * Through {@see ToolTrace} rather than a local `str_replace`, which is what the run
     * transcript uses: the catalogue has a name for every tool under `ai.tools.names`, and
     * spelling the identifier out here instead put an English phrase — *Search tasks* — in
     * the middle of an otherwise Arabic notification, on the one screen whose whole job is
     * to be understood before somebody approves it.
     */
    private function toolLabel(): string
    {
        return ToolTrace::humanise((string) $this->toolRun->tool);
    }

    private function context(): AiRun
    {
        return $this->run->loadMissing(['workspace', 'user']);
    }
}
