<?php

declare(strict_types=1);

namespace App\Notifications\Ai;

use App\Models\AiRun;
use App\Notifications\PlanvioNotification;
use App\Notifications\Support\PlanvioUrl;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * An agent run ended badly — failed outright, ran out of its limits, or finished partway.
 *
 * The stored error is the provider's or the tool's own message. It is shown to the person
 * who started the run because a silent failure is indistinguishable from an agent that
 * decided to do nothing, and those two need very different responses.
 */
final class AiRunFailed extends PlanvioNotification
{
    public function __construct(private readonly AiRun $run) {}

    public function category(): string
    {
        return 'ai.run_failed';
    }

    public function workspaceId(): ?int
    {
        return (int) $this->run->workspace_id;
    }

    public function projectId(): ?int
    {
        $projectId = $this->run->project_id;

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
            title: $this->headline(),
            body: $this->reason(),
            url: PlanvioUrl::aiRun($run->workspace, (string) $run->uuid),
            actor: null,
            subjectType: $run->getMorphClass(),
            subjectId: (int) $run->getKey(),
            extra: [
                'ai_run_id' => (int) $run->getKey(),
                'ai_run_uuid' => (string) $run->uuid,
                'status' => $run->status->value,
                'mode' => $run->mode->value,
                'trigger' => $run->trigger->value,
                'objective' => $run->objective,
                'steps' => (int) $run->steps,
                'tool_call_count' => (int) $run->tool_call_count,
                'error_count' => (int) $run->error_count,
                'error' => $run->error,
            ],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $run = $this->context();

        $meta = [
            ['label' => __('Outcome'), 'value' => $run->status->label()],
            ['label' => __('Mode'), 'value' => $run->mode->label()],
            ['label' => __('Steps taken'), 'value' => (string) (int) $run->steps],
            ['label' => __('Actions attempted'), 'value' => (string) (int) $run->tool_call_count],
        ];

        $objective = $run->objective;

        if (is_string($objective) && trim($objective) !== '') {
            $meta[] = ['label' => __('Objective'), 'value' => $objective];
        }

        return $this->planvioMail(
            subject: $this->headline(),
            title: $this->headline(),
            intro: [$this->reason()],
            meta: $meta,
            actionText: __('See the full run'),
            actionUrl: PlanvioUrl::aiRun($run->workspace, (string) $run->uuid),
            outro: [__('Anything the assistant had already done stays done; nothing was rolled back.')],
        );
    }

    private function headline(): string
    {
        return __('An assistant run did not finish');
    }

    private function reason(): string
    {
        $error = $this->run->error;

        if (is_string($error) && trim($error) !== '') {
            return $error;
        }

        return __('It stopped with the status :status.', ['status' => $this->run->status->label()]);
    }

    private function context(): AiRun
    {
        return $this->run->loadMissing(['workspace', 'user']);
    }
}
