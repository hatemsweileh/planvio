<?php

declare(strict_types=1);

namespace App\Ai\Tools\Concerns;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Enums\Permission;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;

/**
 * The half of `add_tag` and `remove_tag` that is identical in both.
 *
 * `taggables` is polymorphic and carries no `workspace_id` of its own, so the tenant boundary
 * lives entirely in the pair of rows it joins: a tag from one workspace attached to a task in
 * another would leave no column anywhere that looks wrong. Both sides are therefore resolved
 * inside the bound workspace before either is used, and the record's own update permission —
 * not the tag's — is what authorises the change, exactly as `TagPolicy::attach()` says.
 *
 * Kept in one trait because the two tools would otherwise hold two copies of that reasoning,
 * and the copy that gets edited is never the one with the bug.
 */
trait ResolvesTaggables
{
    /**
     * The task or project being tagged, resolved and authorised for editing.
     */
    protected function taggable(Arguments $in, AgentContext $ctx): Task|Project|ToolResult
    {
        $id = $in->int('subject_id');
        $type = $in->string('subject_type');

        if ($type === 'task') {
            $task = $this->resolveTask($id, $ctx);

            if (! $task instanceof Task) {
                return $this->notFound(__('task'), $id);
            }

            $ctx->assertInWorkspace($task);

            return $ctx->can(Permission::TaskUpdate, $task)
                ? $task
                : $this->denied($ctx, __('change task :key', ['key' => $task->key]));
        }

        $project = $this->resolveProject($id, $ctx);

        if (! $project instanceof Project) {
            return $this->notFound(__('project'), $id);
        }

        $ctx->assertInWorkspace($project);

        return $ctx->can(Permission::ProjectUpdate, $project)
            ? $project
            : $this->denied($ctx, __('change project :project', ['project' => $project->name]));
    }

    /**
     * The tag named by `tag_id` or `tag`, within the bound workspace.
     *
     * Neither tool creates one. A workspace's tag vocabulary is shared furniture, and an
     * agent inventing "urgent-ish" next to "urgent" degrades it for everyone who filters by
     * it — so an unknown name comes back naming the tags that do exist.
     */
    protected function taggableTag(Arguments $in, AgentContext $ctx): Tag|ToolResult
    {
        $id = $in->nullableInt('tag_id');

        if ($id !== null) {
            $tag = $this->resolveTag($id, $ctx);

            return $tag instanceof Tag ? $tag : $this->notFound(__('tag'), $id);
        }

        $name = $in->text('tag');

        if ($name === null) {
            return ToolResult::failed(
                __('Give either tag or tag_id.'),
                'invalid_arguments',
            );
        }

        $tag = $this->resolveTagNamed($name, $ctx);

        if ($tag instanceof Tag) {
            return $tag;
        }

        return ToolResult::failed(
            __('This workspace has no tag called ":name". Its tags are: :tags.', [
                'name' => self::clip($name, 60),
                'tags' => implode(', ', $this->workspaceTagNames($ctx)),
            ]),
            'unknown_tag',
        );
    }

    protected function taggableLabel(Task|Project $subject): string
    {
        return $subject instanceof Task
            ? __('task :key', ['key' => $subject->key])
            : __('project :name', ['name' => $subject->name]);
    }

    /**
     * The workspace's tag names, bounded — a workspace with two hundred tags must not push
     * two hundred names into a failure message the model reads back.
     *
     * @return list<string>
     */
    private function workspaceTagNames(AgentContext $ctx): array
    {
        return $ctx->bindWorkspace(static fn (): array => Tag::query()
            ->forWorkspace($ctx->workspaceId())
            ->ordered()
            ->limit(40)
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all());
    }
}
