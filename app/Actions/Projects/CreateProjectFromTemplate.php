<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Workspaces\StatusDefinition;
use App\Enums\MilestoneStatus;
use App\Enums\Priority;
use App\Enums\ViewType;
use App\Exceptions\InvalidProjectTemplate;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\SavedView;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Materialises a project template into a real project.
 *
 * `project_templates.definition` is free-form JSON that may have been authored by hand, by an
 * earlier release, or by the AI. Every section is therefore read defensively: an unreadable
 * row is skipped rather than aborting the build, and a reference that resolves to nothing
 * (a task naming a milestone the template does not define) falls back to null instead of
 * writing a dangling id.
 *
 * The definition shape, all sections optional:
 *
 * ```
 * statuses:   [{name, color, category, is_default, is_completed}]
 * milestones: [{ref, name, description, status, start_offset_days, due_offset_days}]
 * tasks:      [{ref, title, description, priority, status, milestone, parent,
 *               estimate_minutes, start_offset_days, due_offset_days, tags: [], checklist: []}]
 * tags:       [{name, color}]
 * views:      [{name, type, filters, sorts, columns, group_by, is_shared, is_pinned}]
 * ```
 *
 * Day offsets are counted from the project's start date, or from today when it has none, so
 * one template produces a sensible schedule whenever it is used.
 *
 * The whole materialisation shares the project's transaction: a project holding half a
 * template is worse than no project, because nothing tells the person which half is missing.
 */
final class CreateProjectFromTemplate
{
    public function __construct(
        private readonly CreateProject $createProject,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @throws InvalidProjectTemplate
     */
    public function __invoke(
        Workspace $workspace,
        User $owner,
        ProjectTemplate $template,
        ProjectAttributes $attributes,
    ): Project {
        $this->assertUsable($workspace, $template);

        // The caller's own values always win; the template only fills what was left out.
        $effective = new ProjectAttributes(
            name: $this->firstFilled($attributes->name, (string) $template->name),
            key: $attributes->key,
            slug: $attributes->slug,
            description: $this->firstFilled($attributes->description, $template->description),
            icon: $this->firstFilled($attributes->icon, $template->icon),
            logoPath: $attributes->logoPath,
            color: $this->firstFilled($attributes->color, $template->color),
            type: $attributes->type ?? $template->type,
            statusId: $attributes->statusId,
            health: $attributes->health,
            healthNote: $attributes->healthNote,
            priority: $attributes->priority,
            ownerId: $attributes->ownerId,
            managerId: $attributes->managerId,
            clientName: $attributes->clientName,
            department: $attributes->department,
            startDate: $attributes->startDate,
            targetDate: $attributes->targetDate,
            budget: $attributes->budget,
            currency: $attributes->currency,
            settings: $attributes->settings,
            aiSettings: $attributes->aiSettings,
        );

        $statuses = StatusDefinition::listFrom($template->section('statuses'));

        $project = DB::transaction(function () use ($workspace, $owner, $effective, $template, $statuses): Project {
            $project = ($this->createProject)($workspace, $owner, $effective, $statuses === [] ? null : $statuses);

            $anchor = $project->start_date instanceof Carbon
                ? $project->start_date->copy()
                : Carbon::today();

            $tags = $this->materialiseTags($project, $template);
            $milestones = $this->materialiseMilestones($project, $template, $anchor);
            $taskCount = $this->materialiseTasks($project, $template, $owner, $anchor, $milestones, $tags);
            $viewCount = $this->materialiseViews($project, $template);

            $this->activity->forUser($owner)->log($project, 'created_from_template', [
                'template_id' => (int) $template->getKey(),
                'template_name' => (string) $template->name,
                'milestones' => count($milestones),
                'tasks' => $taskCount,
                'views' => $viewCount,
            ]);

            return $project;
        });

        return $project->refresh();
    }

    private function assertUsable(Workspace $workspace, ProjectTemplate $template): void
    {
        if (! $template->is_active) {
            throw InvalidProjectTemplate::inactive($template);
        }

        if ($template->workspace_id !== null && (int) $template->workspace_id !== (int) $workspace->getKey()) {
            throw InvalidProjectTemplate::notAvailableIn($template, $workspace);
        }

        if (! is_array($template->definition)) {
            throw InvalidProjectTemplate::malformed($template, 'definition is not an object');
        }
    }

    /* ------------------------------------------------------------------ *
     * Sections
     * ------------------------------------------------------------------ */

    /**
     * Workspace tags are reused rather than duplicated: a template asking for "Urgent" in a
     * workspace that already has one must not create a second.
     *
     * @return array<string, int> tag name, lower-cased, to tag id
     */
    private function materialiseTags(Project $project, ProjectTemplate $template): array
    {
        $map = [];

        foreach ($template->section('tags') as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $slug = Str::slug($name);

            if ($name === '' || $slug === '') {
                continue;
            }

            $tag = Tag::withoutWorkspaceScope()->firstOrCreate(
                [
                    'workspace_id' => $project->workspace_id,
                    'slug' => mb_substr($slug, 0, 255),
                ],
                [
                    'name' => mb_substr($name, 0, 255),
                    'color' => trim((string) ($row['color'] ?? '')) ?: 'gray',
                ],
            );

            $map[mb_strtolower($name)] = (int) $tag->getKey();
        }

        return $map;
    }

    /**
     * @return array<string, int> reference (the template's `ref`, or the name) to milestone id
     */
    private function materialiseMilestones(Project $project, ProjectTemplate $template, Carbon $anchor): array
    {
        $map = [];
        $position = 0;

        foreach ($template->section('milestones') as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $milestone = Milestone::query()->create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->getKey(),
                'name' => mb_substr($name, 0, 255),
                'description' => $this->text($row['description'] ?? null),
                'status' => MilestoneStatus::tryFrom((string) ($row['status'] ?? '')) ?? MilestoneStatus::Planned,
                'start_date' => $this->offsetDate($anchor, $row['start_offset_days'] ?? null),
                'due_date' => $this->offsetDate($anchor, $row['due_offset_days'] ?? null),
                'position' => $position,
                'progress' => 0,
            ]);

            $reference = trim((string) ($row['ref'] ?? $name));
            $map[mb_strtolower($reference)] = (int) $milestone->getKey();
            $map[mb_strtolower($name)] ??= (int) $milestone->getKey();
            $position++;
        }

        return $map;
    }

    /**
     * @param array<string, int> $milestones
     * @param array<string, int> $tags
     */
    private function materialiseTasks(
        Project $project,
        ProjectTemplate $template,
        User $owner,
        Carbon $anchor,
        array $milestones,
        array $tags,
    ): int {
        $rows = array_values(array_filter($template->section('tasks'), 'is_array'));

        if ($rows === []) {
            return 0;
        }

        $statuses = TaskStatus::query()
            ->withoutWorkspaceScope()
            ->where('project_id', $project->getKey())
            ->ordered()
            ->get();

        $defaultStatusId = (int) ($statuses->firstWhere('is_default', true)?->getKey()
            ?? $statuses->first()?->getKey()
            ?? 0);

        if ($defaultStatusId === 0) {
            throw InvalidProjectTemplate::malformed($template, 'the project has no board columns to place tasks in');
        }

        /** @var array<string, int> $byName */
        $byName = [];

        foreach ($statuses as $status) {
            $byName[mb_strtolower((string) $status->name)] = (int) $status->getKey();
        }

        /** @var array<string, int> $taskRefs */
        $taskRefs = [];
        /** @var array<int, array{0: Task, 1: array<array-key, mixed>}> $created */
        $created = [];
        $number = 0;
        $positions = [];

        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? $row['name'] ?? ''));

            if ($title === '') {
                continue;
            }

            $statusId = $this->resolveStatusId($row['status'] ?? null, $byName, $defaultStatusId);
            $positions[$statusId] = ($positions[$statusId] ?? 0) + 1;
            $number++;

            $task = Task::query()->create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->getKey(),
                'number' => $number,
                'title' => mb_substr($title, 0, 255),
                'description' => $this->text($row['description'] ?? null),
                'status_id' => $statusId,
                'priority' => Priority::tryFrom((string) ($row['priority'] ?? '')) ?? Priority::Medium,
                'assignee_id' => null,
                'reporter_id' => $owner->getKey(),
                'milestone_id' => $this->resolveMilestoneId($row['milestone'] ?? null, $milestones),
                'start_date' => $this->offsetDate($anchor, $row['start_offset_days'] ?? null),
                'due_date' => $this->offsetDate($anchor, $row['due_offset_days'] ?? null),
                'estimate_minutes' => $this->positiveInt($row['estimate_minutes'] ?? null),
                'position' => $positions[$statusId] * 1000,
                'progress' => 0,
                'created_by' => $owner->getKey(),
                'ai_generated' => false,
            ]);

            $reference = trim((string) ($row['ref'] ?? $title));
            $taskRefs[mb_strtolower($reference)] = (int) $task->getKey();
            $taskRefs[mb_strtolower($title)] ??= (int) $task->getKey();

            $created[] = [$task, $row];
        }

        // Parents in a second pass: a template may name a parent that is defined after the
        // child, and forward references are the normal way people write outlines.
        foreach ($created as [$task, $row]) {
            $this->attachTaskExtras($task, $row, $taskRefs, $tags);
        }

        // Numbers were handed out in a batch rather than through
        // {@see \App\Actions\Tasks\TaskNumbers}, which takes a row lock per task. That lock
        // exists to serialise concurrent creators; here the project was inserted moments ago
        // in this same transaction and is not visible to anyone else yet, so there is nothing
        // to serialise against — and a fifty-task template would pay fifty locked reads for it.
        $project->forceFill(['task_number_seq' => $number])->save();

        return $number;
    }

    private function materialiseViews(Project $project, ProjectTemplate $template): int
    {
        $count = 0;
        $position = 0;

        foreach ($template->section('views') as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            SavedView::query()->create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->getKey(),
                // A template view belongs to the project, not to whoever instantiated it.
                'user_id' => null,
                'name' => mb_substr($name, 0, 255),
                'type' => ViewType::tryFrom((string) ($row['type'] ?? '')) ?? ViewType::Board,
                'filters' => is_array($row['filters'] ?? null) ? $row['filters'] : [],
                'sorts' => is_array($row['sorts'] ?? null) ? $row['sorts'] : null,
                'columns' => is_array($row['columns'] ?? null) ? $row['columns'] : null,
                'group_by' => $this->text($row['group_by'] ?? null),
                'is_shared' => true,
                'is_pinned' => (bool) ($row['is_pinned'] ?? false),
                'position' => $position,
            ]);

            $position++;
            $count++;
        }

        return $count;
    }

    /* ------------------------------------------------------------------ *
     * Row helpers
     * ------------------------------------------------------------------ */

    /**
     * @param array<array-key, mixed> $row
     * @param array<string, int> $taskRefs
     * @param array<string, int> $tags
     */
    private function attachTaskExtras(Task $task, array $row, array $taskRefs, array $tags): void
    {
        $parent = trim((string) ($row['parent'] ?? ''));

        if ($parent !== '') {
            $parentId = $taskRefs[mb_strtolower($parent)] ?? null;

            if ($parentId !== null && $parentId !== (int) $task->getKey()) {
                $task->forceFill(['parent_id' => $parentId])->save();
            }
        }

        $tagIds = [];

        foreach ((array) ($row['tags'] ?? []) as $tagName) {
            $id = $tags[mb_strtolower(trim((string) $tagName))] ?? null;

            if ($id !== null) {
                $tagIds[] = $id;
            }
        }

        if ($tagIds !== []) {
            $task->tags()->syncWithoutDetaching(array_values(array_unique($tagIds)));
        }

        $position = 0;

        foreach ((array) ($row['checklist'] ?? []) as $item) {
            $title = trim(is_array($item) ? (string) ($item['title'] ?? '') : (string) $item);

            if ($title === '') {
                continue;
            }

            TaskChecklistItem::query()->create([
                'task_id' => $task->getKey(),
                'title' => mb_substr($title, 0, 255),
                'is_done' => false,
                'position' => $position,
            ]);

            $position++;
        }
    }

    /**
     * @param array<string, int> $byName
     */
    private function resolveStatusId(mixed $reference, array $byName, int $default): int
    {
        if (is_int($reference) || (is_string($reference) && ctype_digit($reference))) {
            $index = (int) $reference;
            $values = array_values($byName);

            return $values[$index] ?? $default;
        }

        if (is_string($reference) && trim($reference) !== '') {
            return $byName[mb_strtolower(trim($reference))] ?? $default;
        }

        return $default;
    }

    /**
     * @param array<string, int> $milestones
     */
    private function resolveMilestoneId(mixed $reference, array $milestones): ?int
    {
        if (! is_string($reference) || trim($reference) === '') {
            return null;
        }

        return $milestones[mb_strtolower(trim($reference))] ?? null;
    }

    private function offsetDate(Carbon $anchor, mixed $offset): ?Carbon
    {
        if ($offset === null || $offset === '' || ! is_numeric($offset)) {
            return null;
        }

        return $anchor->copy()->addDays((int) $offset)->startOfDay();
    }

    /**
     * The first of the candidates that is a non-blank string, or null.
     */
    private function firstFilled(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
