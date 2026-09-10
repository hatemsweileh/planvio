<?php

declare(strict_types=1);

namespace App\Ai\Tools\Concerns;

use App\Ai\Agent\AgentContext;
use App\Models\Comment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Turning an id the model produced into a record, without ever leaking that the record
 * exists somewhere else.
 *
 * Every id a tool receives arrives inside `<untrusted-data>` or is invented outright, so
 * `findOrFail($id)` is exactly the wrong shape: it resolves globally and then asks questions
 * afterwards, and its 404 vs 403 difference is itself an oracle — a model coaxed into naming
 * "project 812" would learn from the response whether project 812 is real
 * (AI_SECURITY.md, "Workspace isolation").
 *
 * So each resolver here does the same three things in the same order:
 *
 *   1. bind the run's workspace, so `WorkspaceScope` is active for the query;
 *   2. constrain the query to that workspace's id explicitly, because the scope is a
 *      convenience and never the authority (ARCHITECTURE.md section 3);
 *   3. re-assert the resolved record's tenant column before handing it back.
 *
 * A record in another workspace, a soft-deleted record and a record that never existed are
 * therefore indistinguishable from outside: all three return null, and the caller reports
 * one "no such record here" to the model. Authorization is deliberately *not* done here —
 * resolving is not permitting. The tool still has to run `AgentContext::assertInWorkspace()`
 * and `AgentContext::can()` before it touches an Action.
 */
trait ResolvesSubjects
{
    /**
     * A project in the bound workspace, archived ones included.
     *
     * Archived projects resolve on purpose: `archive_project` has to see one to report that
     * it is already archived, and `delete_project` has to reach one to delete it. Trashed
     * projects do not — `Project` soft-deletes, and a deleted project is gone as far as the
     * agent is concerned.
     */
    protected function resolveProject(int $id, AgentContext $ctx): ?Project
    {
        if ($id < 1) {
            return null;
        }

        $project = $ctx->bindWorkspace(static fn (): ?Project => Project::query()
            ->forWorkspace($ctx->workspaceId())
            ->whereKey($id)
            ->first());

        return $project instanceof Project && $ctx->isInWorkspace($project) ? $project : null;
    }

    /**
     * A task in the bound workspace, with its project loaded so `$task->key` renders
     * "WEB-42" rather than "#42" in a summary the model reads back.
     */
    protected function resolveTask(int $id, AgentContext $ctx): ?Task
    {
        if ($id < 1) {
            return null;
        }

        $task = $ctx->bindWorkspace(static fn (): ?Task => Task::query()
            ->forWorkspace($ctx->workspaceId())
            ->with('project')
            ->whereKey($id)
            ->first());

        return $task instanceof Task && $ctx->isInWorkspace($task) ? $task : null;
    }

    protected function resolveMilestone(int $id, AgentContext $ctx): ?Milestone
    {
        if ($id < 1) {
            return null;
        }

        $milestone = $ctx->bindWorkspace(static fn (): ?Milestone => Milestone::query()
            ->forWorkspace($ctx->workspaceId())
            ->with('project')
            ->whereKey($id)
            ->first());

        return $milestone instanceof Milestone && $ctx->isInWorkspace($milestone) ? $milestone : null;
    }

    /**
     * A person, but only one this workspace can already see.
     *
     * `users` carries no tenant column, so the workspace constraint has to come from
     * `workspace_members`: the membership row is resolved first and the user is loaded only
     * if it exists. Without that ordering the tool would happily hand the model a name and
     * an email address belonging to another customer's workspace.
     */
    protected function resolveUser(int $id, AgentContext $ctx): ?User
    {
        if ($id < 1) {
            return null;
        }

        return $ctx->bindWorkspace(static function () use ($id, $ctx): ?User {
            $isMember = WorkspaceMember::query()
                ->forWorkspace($ctx->workspaceId())
                ->where('user_id', $id)
                ->exists();

            if (! $isMember) {
                return null;
            }

            return User::query()->whereKey($id)->first();
        });
    }

    /**
     * The membership row itself — what the policies for member management authorise against,
     * and what carries the role the approval card has to show.
     */
    protected function resolveMembership(int $userId, AgentContext $ctx): ?WorkspaceMember
    {
        if ($userId < 1) {
            return null;
        }

        $membership = $ctx->bindWorkspace(static fn (): ?WorkspaceMember => WorkspaceMember::query()
            ->forWorkspace($ctx->workspaceId())
            ->where('user_id', $userId)
            ->first());

        return $membership instanceof WorkspaceMember && $ctx->isInWorkspace($membership)
            ? $membership
            : null;
    }

    /**
     * Project membership for an already-resolved project and user.
     *
     * `project_members` has no `workspace_id` of its own, which is why this takes the models
     * rather than ids: the tenancy question was settled when the project was resolved, and
     * re-deriving it from a raw id here would quietly reintroduce the hole.
     */
    protected function resolveProjectMembership(Project $project, User $user): ?ProjectMember
    {
        return ProjectMember::query()
            ->where('project_id', $project->getKey())
            ->where('user_id', $user->getKey())
            ->first();
    }

    /**
     * A tag in the bound workspace, by id.
     */
    protected function resolveTag(int $id, AgentContext $ctx): ?Tag
    {
        if ($id < 1) {
            return null;
        }

        $tag = $ctx->bindWorkspace(static fn (): ?Tag => Tag::query()
            ->forWorkspace($ctx->workspaceId())
            ->whereKey($id)
            ->first());

        return $tag instanceof Tag && $ctx->isInWorkspace($tag) ? $tag : null;
    }

    /**
     * A tag by the name a person would type.
     *
     * Tags are `unique(workspace_id, slug)`, so the slug is the reliable half: "Needs Design",
     * "needs design" and "needs-design" are one tag, and matching on the slug is what makes a
     * model's paraphrase land on it. The literal name is tried first so a workspace that has
     * deliberately kept two similar names still resolves the exact one.
     */
    protected function resolveTagNamed(string $name, AgentContext $ctx): ?Tag
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $slug = Str::slug($name);

        $candidates = $ctx->bindWorkspace(static fn (): Collection => Tag::query()
            ->forWorkspace($ctx->workspaceId())
            ->where(static function (Builder $match) use ($name, $slug): void {
                $match->where('name', $name);

                if ($slug !== '') {
                    $match->orWhere('slug', $slug);
                }
            })
            ->limit(10)
            ->get());

        // Preference expressed in PHP rather than in SQL: the candidate set is at most a
        // handful of rows, and no fragment of a query anywhere in the AI layer is worth
        // assembling by hand.
        $tag = $candidates->first(static fn (Tag $candidate): bool => (string) $candidate->name === $name)
            ?? $candidates->first();

        return $tag instanceof Tag && $ctx->isInWorkspace($tag) ? $tag : null;
    }

    /**
     * A board column, resolved inside one project rather than the workspace.
     *
     * `task_statuses` is per project, so a column named "Done" exists many times over in a
     * workspace and only the project's own copy is a legal target — `ChangeTaskStatus` throws
     * on any other. Ids and names are both accepted because the model reads names in context
     * and rarely sees an id; the name match is case-insensitive on a bounded per-project set,
     * which is small enough to compare in PHP rather than trusting a collation.
     */
    protected function resolveTaskStatus(Project $project, int|string $status, AgentContext $ctx): ?TaskStatus
    {
        $columns = $ctx->bindWorkspace(static fn (): Collection => TaskStatus::query()
            ->forWorkspace($ctx->workspaceId())
            ->forProject($project)
            ->ordered()
            ->get());

        if (is_int($status)) {
            $found = $columns->first(static fn (TaskStatus $column): bool => (int) $column->getKey() === $status);

            return $found instanceof TaskStatus && $ctx->isInWorkspace($found) ? $found : null;
        }

        $needle = mb_strtolower(trim($status));

        if ($needle === '') {
            return null;
        }

        $found = $columns->first(
            static fn (TaskStatus $column): bool => mb_strtolower((string) $column->name) === $needle,
        );

        return $found instanceof TaskStatus && $ctx->isInWorkspace($found) ? $found : null;
    }

    /**
     * The names of a project's board columns, for a failure message that tells the model what
     * it could have said instead of what it did.
     *
     * @return list<string>
     */
    protected function taskStatusNames(Project $project, AgentContext $ctx): array
    {
        return $ctx->bindWorkspace(static fn (): array => TaskStatus::query()
            ->forWorkspace($ctx->workspaceId())
            ->forProject($project)
            ->ordered()
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all());
    }

    protected function resolveWikiPage(int $id, AgentContext $ctx): ?WikiPage
    {
        if ($id < 1) {
            return null;
        }

        $page = $ctx->bindWorkspace(static fn (): ?WikiPage => WikiPage::query()
            ->forWorkspace($ctx->workspaceId())
            ->whereKey($id)
            ->first());

        return $page instanceof WikiPage && $ctx->isInWorkspace($page) ? $page : null;
    }

    protected function resolveComment(int $id, AgentContext $ctx): ?Comment
    {
        if ($id < 1) {
            return null;
        }

        $comment = $ctx->bindWorkspace(static fn (): ?Comment => Comment::query()
            ->forWorkspace($ctx->workspaceId())
            ->whereKey($id)
            ->first());

        return $comment instanceof Comment && $ctx->isInWorkspace($comment) ? $comment : null;
    }

    /**
     * A task that does not exist yet, carrying only the two columns the policies read.
     *
     * `TaskPolicy::assign()` answers against a task's workspace and project, which are both
     * known before the row is written — so creating a task already assigned to somebody can
     * be authorised with the same permission that assigning it afterwards would need, rather
     * than slipping through `task.create` alone. The probe is never saved and never leaves
     * the authorization check.
     */
    protected function unsavedTaskIn(Project $project): Task
    {
        $probe = new Task;

        $probe->workspace_id = $project->workspace_id;
        $probe->project_id = $project->getKey();

        return $probe;
    }
}
