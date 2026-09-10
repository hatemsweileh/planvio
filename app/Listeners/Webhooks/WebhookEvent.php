<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Events\Comments\CommentCreated;
use App\Events\Members\MemberInvited;
use App\Events\Members\MemberJoined;
use App\Events\Milestones\MilestoneCompleted;
use App\Events\Projects\ProjectCreated;
use App\Events\Projects\ProjectUpdated;
use App\Events\Tasks\TaskAssigned;
use App\Events\Tasks\TaskCompleted;
use App\Events\Tasks\TaskCreated;
use App\Events\Tasks\TaskStatusChanged;
use App\Events\Tasks\TaskUpdated;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Support\PlanvioUrl;
use Illuminate\Support\Carbon;

/**
 * The public shape of a Planvio webhook, and the one place a domain event is translated
 * into it.
 *
 * This is a contract with somebody else's code. Two rules follow from that and are worth
 * stating because both are easy to break by accident:
 *
 *   - **Never send a credential or a secret.** `member.invited` carries the address and the
 *     role but not the invitation token, which is a bearer credential. Nothing here reads a
 *     column that is encrypted at rest.
 *   - **Never send more than the event is about.** A receiver gets identifiers and the
 *     handful of fields that describe the change, not a serialised model — a model's columns
 *     move between releases, and an integration built on them breaks when they do.
 *
 * An event with no mapping returns null and no delivery is attempted, so subscribing a
 * webhook to an event Planvio does not emit is inert rather than an error.
 */
final readonly class WebhookEvent
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        public string $name,
        public int $workspaceId,
        public array $data,
        public ?User $actor,
    ) {}

    public static function from(object $event): ?self
    {
        return match (true) {
            $event instanceof TaskCreated => self::make('task.created', $event->task->workspace_id, [
                'task' => self::task($event->task),
            ], $event->actor),

            $event instanceof TaskUpdated => self::make('task.updated', $event->task->workspace_id, [
                'task' => self::task($event->task),
                'changes' => $event->changes,
            ], $event->actor),

            $event instanceof TaskStatusChanged => self::make('task.status_changed', $event->task->workspace_id, [
                'task' => self::task($event->task),
                'from' => $event->from === null ? null : [
                    'id' => (int) $event->from->getKey(),
                    'name' => (string) $event->from->name,
                    'category' => $event->from->category->value,
                ],
                'to' => [
                    'id' => (int) $event->to->getKey(),
                    'name' => (string) $event->to->name,
                    'category' => $event->to->category->value,
                ],
                'completed' => $event->isCompleted,
            ], $event->actor),

            $event instanceof TaskAssigned => self::make('task.assigned', $event->task->workspace_id, [
                'task' => self::task($event->task),
                'assignee' => self::user($event->assignee),
                'previous_assignee' => self::user($event->previous),
            ], $event->actor),

            $event instanceof TaskCompleted => self::make('task.completed', $event->task->workspace_id, [
                'task' => self::task($event->task),
                'status' => [
                    'id' => (int) $event->status->getKey(),
                    'name' => (string) $event->status->name,
                    'category' => $event->status->category->value,
                ],
            ], $event->actor),

            $event instanceof CommentCreated => self::make('comment.created', $event->comment->workspace_id, [
                'comment' => [
                    'id' => (int) $event->comment->getKey(),
                    'body' => (string) $event->comment->body,
                    'author_type' => $event->comment->author_type->value,
                    'parent_id' => $event->comment->parent_id === null ? null : (int) $event->comment->parent_id,
                    'created_at' => $event->comment->created_at?->toIso8601String(),
                ],
                'subject' => [
                    'type' => $event->commentable->getMorphClass(),
                    'id' => (int) $event->commentable->getKey(),
                ],
                'mentioned_user_ids' => $event->mentionedUserIds,
            ], $event->actor),

            $event instanceof ProjectCreated => self::make('project.created', $event->project->workspace_id, [
                'project' => self::project($event->project),
            ], $event->owner),

            $event instanceof ProjectUpdated => self::make('project.updated', $event->project->workspace_id, [
                'project' => self::project($event->project),
                'changes' => $event->changes,
            ], $event->actor),

            $event instanceof MilestoneCompleted => self::make('milestone.completed', $event->milestone->workspace_id, [
                'milestone' => self::milestone($event->milestone),
            ], $event->actor),

            // The token is deliberately absent: it is a working credential, and a webhook
            // endpoint is not an audience for one.
            $event instanceof MemberInvited => self::make('member.invited', $event->invitation->workspace_id, [
                'invitation' => [
                    'id' => (int) $event->invitation->getKey(),
                    'email' => (string) $event->invitation->email,
                    'role' => $event->invitation->role->value,
                    'project_id' => $event->invitation->project_id === null
                        ? null
                        : (int) $event->invitation->project_id,
                    'expires_at' => $event->invitation->expires_at?->toIso8601String(),
                ],
            ], $event->inviter),

            $event instanceof MemberJoined => self::make('member.joined', $event->workspace->getKey(), [
                'member' => [
                    'user' => self::user($event->user),
                    'role' => $event->member->role->value,
                    'joined_at' => $event->member->joined_at?->toIso8601String(),
                    'via_invitation' => $event->invitation !== null,
                ],
            ], $event->user),

            default => null,
        };
    }

    /**
     * The body that is signed and posted. Deliberately flat and versionless: adding a key is
     * backwards-compatible for every receiver, renaming one is not, so nothing is renamed.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return [
            'event' => $this->name,
            'occurred_at' => Carbon::now()->toIso8601String(),
            'workspace_id' => $this->workspaceId,
            'actor' => self::user($this->actor),
            'data' => $this->data,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function make(string $name, mixed $workspaceId, array $data, ?User $actor): ?self
    {
        if ($workspaceId === null) {
            return null;
        }

        return new self($name, (int) $workspaceId, $data, $actor);
    }

    /**
     * @return array<string, mixed>
     */
    private static function task(Task $task): array
    {
        $task->loadMissing(['project', 'status', 'workspace']);

        return [
            'id' => (int) $task->getKey(),
            'key' => $task->key,
            'number' => (int) $task->number,
            'title' => (string) $task->title,
            'project_id' => (int) $task->project_id,
            'project_key' => $task->project?->key,
            'status_id' => $task->status_id === null ? null : (int) $task->status_id,
            'status' => $task->status?->name,
            'priority' => $task->priority->value,
            'assignee_id' => $task->assignee_id === null ? null : (int) $task->assignee_id,
            'reporter_id' => $task->reporter_id === null ? null : (int) $task->reporter_id,
            'milestone_id' => $task->milestone_id === null ? null : (int) $task->milestone_id,
            'parent_id' => $task->parent_id === null ? null : (int) $task->parent_id,
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'progress' => (int) $task->progress,
            'ai_generated' => (bool) $task->ai_generated,
            'url' => PlanvioUrl::task($task),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function project(Project $project): array
    {
        $project->loadMissing('workspace');

        return [
            'id' => (int) $project->getKey(),
            'key' => (string) $project->key,
            'name' => (string) $project->name,
            'slug' => (string) $project->slug,
            'type' => $project->type->value,
            'health' => $project->health->value,
            'priority' => $project->priority->value,
            'status_id' => $project->status_id === null ? null : (int) $project->status_id,
            'owner_id' => $project->owner_id === null ? null : (int) $project->owner_id,
            'manager_id' => $project->manager_id === null ? null : (int) $project->manager_id,
            'start_date' => $project->start_date?->toDateString(),
            'target_date' => $project->target_date?->toDateString(),
            'completed_at' => $project->completed_at?->toIso8601String(),
            'progress' => (int) $project->progress,
            'is_archived' => (bool) $project->is_archived,
            'url' => PlanvioUrl::project($project),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function milestone(Milestone $milestone): array
    {
        $milestone->loadMissing('workspace');

        return [
            'id' => (int) $milestone->getKey(),
            'name' => (string) $milestone->name,
            'project_id' => (int) $milestone->project_id,
            'status' => $milestone->status->value,
            'start_date' => $milestone->start_date?->toDateString(),
            'due_date' => $milestone->due_date?->toDateString(),
            'completed_at' => $milestone->completed_at?->toIso8601String(),
            'progress' => (int) $milestone->progress,
            'owner_id' => $milestone->owner_id === null ? null : (int) $milestone->owner_id,
            'url' => PlanvioUrl::milestone($milestone),
        ];
    }

    /**
     * @return array{id: int, name: string, email: string}|null
     */
    private static function user(?User $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
        ];
    }
}
