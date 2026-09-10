<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Models\Comment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Support\Facades\Route;

/**
 * Deep links written into notification payloads and mail buttons.
 *
 * Every notification stores a URL rather than the ingredients for one, because the inbox
 * renders rows straight out of the JSON payload and must not have to guess how a task is
 * addressed. That makes this class the single place where the product's URL shape is
 * decided for anything sent outside the browser.
 *
 * Each method prefers a named route and falls back to the literal path from
 * ARCHITECTURE.md §1 (`/w/{workspace}`). The fallback is not a nicety: notifications are
 * built from queue workers and console commands, and a link that only exists once the app
 * routes happen to be loaded is a link that silently becomes `/` in half of them.
 *
 * URLs are absolute. A relative link in an e-mail is not a link.
 */
final class PlanvioUrl
{
    public static function task(Task $task): string
    {
        return self::resolve(
            'app.tasks.show',
            ['workspace' => self::slug($task->workspace), 'task' => $task->getKey()],
            self::workspacePath($task->workspace).'/tasks/'.$task->getKey(),
        );
    }

    public static function project(Project $project): string
    {
        return self::resolve(
            'app.projects.show',
            ['workspace' => self::slug($project->workspace), 'project' => $project->slug ?? $project->getKey()],
            self::workspacePath($project->workspace).'/projects/'.($project->slug ?? $project->getKey()),
        );
    }

    public static function milestone(Milestone $milestone): string
    {
        return self::resolve(
            'app.milestones.show',
            ['workspace' => self::slug($milestone->workspace), 'milestone' => $milestone->getKey()],
            self::workspacePath($milestone->workspace).'/milestones/'.$milestone->getKey(),
        );
    }

    /**
     * The thing the comment is on, anchored at the comment itself.
     *
     * The subject is addressed by class name and id rather than by model, so a notification
     * built from a payload it has already flattened does not have to re-hydrate anything.
     */
    public static function comment(Comment $comment, string $subjectType, int $subjectId): string
    {
        return self::subject($subjectType, $subjectId, $comment->workspace).'#comment-'.$comment->getKey();
    }

    /**
     * A link to whatever `$type`/`$id` addresses, or the workspace root when the type is
     * not one the product has a screen for.
     */
    public static function subject(string $type, int $id, ?Workspace $workspace = null): string
    {
        $base = self::workspacePath($workspace);

        return url($base.match ($type) {
            Task::class => '/tasks/'.$id,
            Project::class => '/projects/'.$id,
            Milestone::class => '/milestones/'.$id,
            default => '',
        });
    }

    /**
     * The AI run detail screen — the audit trail behind an action the agent took.
     */
    public static function aiRun(?Workspace $workspace, string $uuid): string
    {
        return self::resolve(
            'app.ai.runs.show',
            ['workspace' => self::slug($workspace), 'run' => $uuid],
            self::workspacePath($workspace).'/ai/runs/'.$uuid,
        );
    }

    /**
     * Where a person goes to approve or reject what the agent has queued.
     */
    public static function aiApprovals(?Workspace $workspace): string
    {
        return self::resolve(
            'app.ai.approvals',
            ['workspace' => self::slug($workspace)],
            self::workspacePath($workspace).'/ai/approvals',
        );
    }

    public static function inbox(?Workspace $workspace): string
    {
        return self::resolve(
            'app.inbox',
            ['workspace' => self::slug($workspace)],
            self::workspacePath($workspace).'/inbox',
        );
    }

    /**
     * The invitation accept screen. The token is a bearer credential, so this is the one
     * URL in the class that must never be written to the notifications table.
     */
    public static function invitation(string $token): string
    {
        return self::resolve('invitations.accept', ['token' => $token], '/invitations/'.$token);
    }

    /**
     * `/w/{slug}` when a workspace is known, the site root when it is not.
     */
    private static function workspacePath(?Workspace $workspace): string
    {
        $slug = self::slug($workspace);

        return $slug === null ? '' : '/w/'.$slug;
    }

    private static function slug(?Workspace $workspace): ?string
    {
        if (! $workspace instanceof Workspace) {
            return null;
        }

        $slug = $workspace->slug;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function resolve(string $name, array $parameters, string $fallback): string
    {
        if (Route::has($name) && ! in_array(null, $parameters, true)) {
            return route($name, $parameters);
        }

        return url($fallback);
    }
}
