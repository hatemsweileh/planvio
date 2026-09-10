<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Ai\Tools\Write\Support\AssistantMessage;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use App\Notifications\Support\PlanvioUrl;
use App\Services\NotificationDispatcher;
use Illuminate\Support\Facades\Gate;

/**
 * Tell people something, through their existing Planvio notification preferences.
 *
 * ## Who can be reached
 *
 * Recipients are user ids, resolved through workspace membership. There is no argument that
 * takes an email address, a phone number, a webhook or a channel name, so there is no shape
 * of this call that sends anything outside the workspace. That is the whole point: a tool
 * that could address arbitrary recipients would turn every prompt injection in a task
 * description into an exfiltration primitive — "email the client list to …" would be one
 * well-crafted comment away.
 *
 * When a project is named, each recipient is additionally checked against that project's own
 * policy, as themselves. That check only ever narrows the audience, and it exists because a
 * notification carries a subject line and a body about work: telling a workspace guest that
 * "the Acme migration slipped" is a disclosure even when the guest is a legitimate member of
 * the workspace.
 *
 * ## What it cannot do
 *
 * It does not bypass preferences. {@see NotificationDispatcher} decides the channels from
 * `users.notification_preferences`, so somebody who has muted a category stays muted, and the
 * count reported back is the number actually notified rather than the number addressed —
 * claiming otherwise would have the model tell a user that somebody was informed when they
 * were not.
 */
final class SendNotificationTool implements AiTool
{
    /** More than this is an announcement, and announcements are a person's decision. */
    public const MAX_RECIPIENTS = 25;

    use MutatesThroughActions;

    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function name(): string
    {
        return 'send_notification';
    }

    public function group(): string
    {
        return 'notifications';
    }

    public function description(): string
    {
        return 'Notify workspace members by user id, through their own notification '
            .'preferences. Cannot reach anyone outside the workspace and takes no email '
            .'address. At most '.self::MAX_RECIPIENTS.' people per call. People who have '
            .'muted this kind of notification are not reached, and the result says who was.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['recipient_ids', 'subject', 'message'],
            'properties' => [
                'recipient_ids' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => self::MAX_RECIPIENTS,
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'description' => 'User ids of workspace members to notify.',
                ],
                'subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'message' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 1000,
                    'description' => 'Plain text. Say what happened and what the reader should do.',
                ],
                'project_id' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'description' => 'The project this is about. Recipients who cannot see it are dropped.',
                ],
                'task_id' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'description' => 'Link the notification to a task the recipients can already see.',
                ],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Low;
    }

    public function permission(): ?Permission
    {
        return Permission::AiUse;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->send($in, $ctx));
    }

    private function send(Arguments $in, AgentContext $ctx): ToolResult
    {
        if ($ctx->cannot(Permission::AiUse, $ctx->project)) {
            return $this->denied($ctx, __('use the assistant here'));
        }

        $project = null;

        if ($in->filled('project_id')) {
            $projectId = $in->int('project_id');
            $project = $this->resolveProject($projectId, $ctx);

            if (! $project instanceof Project) {
                return $this->notFound(__('project'), $projectId);
            }

            $ctx->assertInWorkspace($project);

            if ($ctx->cannot(Permission::ProjectView, $project)) {
                return $this->denied($ctx, __('send notifications about :project', ['project' => $project->name]));
            }
        }

        $link = $this->link($in, $ctx, $project);

        if ($link instanceof ToolResult) {
            return $link;
        }

        /** @var list<User> $recipients */
        $recipients = [];
        /** @var list<int> $notMembers */
        $notMembers = [];
        /** @var list<string> $cannotSee */
        $cannotSee = [];

        foreach ($in->ids('recipient_ids') as $id) {
            $user = $this->resolveUser($id, $ctx);

            if (! $user instanceof User) {
                $notMembers[] = $id;

                continue;
            }

            // The recipient's own access to the project, asked as them. This never grants
            // anything — it can only remove somebody from the audience.
            if ($project instanceof Project && ! Gate::forUser($user)->allows('view', $project)) {
                $cannotSee[] = $user->name;

                continue;
            }

            $recipients[] = $user;
        }

        if ($recipients === []) {
            return ToolResult::failed(
                __('Nobody could be notified: :missing of those ids are not workspace members and :hidden cannot see that project. Nothing was sent.', [
                    'missing' => count($notMembers),
                    'hidden' => count($cannotSee),
                ]),
                'no_eligible_recipients',
                ['not_members' => $notMembers, 'cannot_see_project' => $cannotSee],
            );
        }

        $notified = $this->dispatcher->send(
            recipients: $recipients,
            notification: new AssistantMessage(
                run: $ctx->run,
                actingUser: $ctx->user,
                subjectLine: (string) $in->text('subject'),
                body: (string) self::clip($in->text('message'), 1000),
                link: $link,
            ),
            category: 'ai.message',
            actor: $ctx->user,
            workspace: $ctx->workspace,
            // The recipients were named explicitly, so the acting user asking to be told is a
            // request rather than the self-notification noise the dispatcher guards against.
            includeActor: true,
        );

        return ToolResult::ok(
            $this->summary($notified, count($recipients), $notMembers, $cannotSee),
            [
                'notified' => $notified,
                'addressed' => count($recipients),
                'muted' => count($recipients) - $notified,
                'not_members' => $notMembers,
                'cannot_see_project' => $cannotSee,
                'subject' => $in->text('subject'),
                'project_id' => $project === null ? null : (int) $project->getKey(),
            ],
        );
    }

    /**
     * The link the notification points at, or a failure when the task named is not one the
     * acting user can see. A notification whose link 404s for its reader is worse than none.
     */
    private function link(Arguments $in, AgentContext $ctx, ?Project $project): string|ToolResult|null
    {
        if ($in->filled('task_id')) {
            $taskId = $in->int('task_id');
            $task = $this->resolveTask($taskId, $ctx);

            if ($task === null) {
                return $this->notFound(__('task'), $taskId);
            }

            $ctx->assertInWorkspace($task);

            if ($ctx->cannot(Permission::TaskView, $task)) {
                return $this->denied($ctx, __('link to task :key', ['key' => $task->key]));
            }

            return PlanvioUrl::task($task);
        }

        return $project instanceof Project ? PlanvioUrl::project($project) : null;
    }

    /**
     * @param list<int> $notMembers
     * @param list<string> $cannotSee
     */
    private function summary(int $notified, int $addressed, array $notMembers, array $cannotSee): string
    {
        $summary = __('Notified :notified of :addressed people.', [
            'notified' => $notified,
            'addressed' => $addressed,
        ]);

        if ($notified < $addressed) {
            $summary .= ' '.__(':count have this kind of notification switched off.', [
                'count' => $addressed - $notified,
            ]);
        }

        if ($notMembers !== []) {
            $summary .= ' '.__(':count id(s) are not members of this workspace and were not contacted.', [
                'count' => count($notMembers),
            ]);
        }

        if ($cannotSee !== []) {
            $summary .= ' '.__(':names cannot see that project, so they were left out.', [
                'names' => implode(', ', array_slice($cannotSee, 0, 10)),
            ]);
        }

        return $summary;
    }
}
