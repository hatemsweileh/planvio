<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Workspaces\StatusDefinition;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectRole;
use App\Events\Projects\ProjectDuplicated;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\SavedView;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskStatus;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Copies a project into a new one in the same workspace.
 *
 * The copy is a normal project from the first statement onwards — nothing links it back to
 * its source, so there is no shared state to keep in step later. That is deliberate: a
 * "duplicate" people can edit freely is what they wanted; a clone that keeps referring to
 * its original is a template, and templates are a different feature.
 *
 * Order matters inside the transaction and is fixed by the foreign keys: board columns and
 * milestones exist before any task can point at one, and parents are set in a second pass
 * because a subtask may be copied before its parent.
 */
final class DuplicateProject
{
    public function __construct(
        private readonly CreateProject $createProject,
        private readonly ActivityLogger $activity,
    ) {}

    public function __invoke(
        Project $source,
        User $owner,
        ProjectAttributes $attributes = new ProjectAttributes,
        DuplicateProjectOptions $options = new DuplicateProjectOptions,
    ): Project {
        $workspace = $source->workspace;

        $effective = $attributes->name !== null && trim($attributes->name) !== ''
            ? $attributes
            : $attributes->withName(__(':name (copy)', ['name' => (string) $source->name]));

        $sourceStatuses = TaskStatus::query()
            ->withoutWorkspaceScope()
            ->where('project_id', $source->getKey())
            ->ordered()
            ->get();

        $template = $this->statusTemplate($sourceStatuses);

        $copy = DB::transaction(function () use (
            $source,
            $owner,
            $workspace,
            $effective,
            $options,
            $sourceStatuses,
            $template,
        ): Project {
            $copy = ($this->createProject)($workspace, $owner, $this->carryOver($source, $effective), $template);

            $statusMap = $this->mapStatuses($sourceStatuses, $copy);
            $milestoneMap = $options->milestones ? $this->copyMilestones($source, $copy, $options) : [];
            $taskCount = $options->tasks
                ? $this->copyTasks($source, $copy, $owner, $options, $statusMap, $milestoneMap)
                : 0;

            if ($options->members) {
                $this->copyMembers($source, $copy);
            }

            if ($options->tags) {
                $this->copyProjectTags($source, $copy);
            }

            $viewCount = $options->savedViews ? $this->copySavedViews($source, $copy) : 0;

            $this->activity->forUser($owner)->log($copy, 'duplicated', [
                'source_project_id' => (int) $source->getKey(),
                'source_project_key' => (string) $source->key,
                'tasks' => $taskCount,
                'milestones' => count($milestoneMap),
                'views' => $viewCount,
            ]);

            return $copy;
        });

        event(new ProjectDuplicated($copy, $source, $owner));

        return $copy->refresh();
    }

    /* ------------------------------------------------------------------ *
     * Shape
     * ------------------------------------------------------------------ */

    /**
     * Fill anything the caller left out from the source, so a bare duplicate really is one.
     */
    private function carryOver(Project $source, ProjectAttributes $attributes): ProjectAttributes
    {
        return new ProjectAttributes(
            name: $attributes->name,
            key: $attributes->key,
            slug: $attributes->slug,
            description: $attributes->description ?? $source->description,
            icon: $attributes->icon ?? $source->icon,
            logoPath: $attributes->logoPath,
            color: $attributes->color ?? $source->color,
            type: $attributes->type ?? $source->type,
            statusId: $attributes->statusId ?? ($source->status_id === null ? null : (int) $source->status_id),
            health: $attributes->health,
            healthNote: $attributes->healthNote,
            priority: $attributes->priority ?? $source->priority,
            ownerId: $attributes->ownerId,
            managerId: $attributes->managerId,
            clientName: $attributes->clientName ?? $source->client_name,
            department: $attributes->department ?? $source->department,
            startDate: $attributes->startDate ?? $source->start_date,
            targetDate: $attributes->targetDate ?? $source->target_date,
            budget: $attributes->budget ?? ($source->budget === null ? null : (string) $source->budget),
            currency: $attributes->currency ?? $source->currency,
            settings: $attributes->settings ?? (is_array($source->settings) ? $source->settings : null),
            aiSettings: $attributes->aiSettings ?? (is_array($source->ai_settings) ? $source->ai_settings : null),
        );
    }

    /**
     * @param Collection<int, TaskStatus> $statuses
     * @return list<StatusDefinition>|null
     */
    private function statusTemplate(Collection $statuses): ?array
    {
        if ($statuses->isEmpty()) {
            return null;
        }

        $definitions = [];
        $position = 0;

        foreach ($statuses as $status) {
            $definitions[] = new StatusDefinition(
                name: (string) $status->name,
                color: (string) $status->color,
                category: $status->category,
                position: $position,
                isDefault: (bool) $status->is_default,
                isCompleted: (bool) $status->is_completed,
            );
            $position++;
        }

        return StatusDefinition::withExactlyOneDefault($definitions);
    }

    /**
     * @param Collection<int, TaskStatus> $sourceStatuses
     * @return array<int, int> source status id to copied status id
     */
    private function mapStatuses(Collection $sourceStatuses, Project $copy): array
    {
        $copied = TaskStatus::query()
            ->withoutWorkspaceScope()
            ->where('project_id', $copy->getKey())
            ->ordered()
            ->get();

        /** @var array<string, int> $byName */
        $byName = [];

        foreach ($copied as $status) {
            $byName[mb_strtolower((string) $status->name)] ??= (int) $status->getKey();
        }

        $fallback = (int) ($copied->firstWhere('is_default', true)?->getKey() ?? $copied->first()?->getKey() ?? 0);

        $map = [];
        $index = 0;

        foreach ($sourceStatuses as $status) {
            $byPosition = $copied[$index] ?? null;

            $map[(int) $status->getKey()] = $byName[mb_strtolower((string) $status->name)]
                ?? ($byPosition === null ? $fallback : (int) $byPosition->getKey());

            $index++;
        }

        return $map;
    }

    /* ------------------------------------------------------------------ *
     * Sections
     * ------------------------------------------------------------------ */

    /**
     * @return array<int, int> source milestone id to copied milestone id
     */
    private function copyMilestones(Project $source, Project $copy, DuplicateProjectOptions $options): array
    {
        $map = [];

        $milestones = Milestone::query()
            ->withoutWorkspaceScope()
            ->where('project_id', $source->getKey())
            ->ordered()
            ->get();

        foreach ($milestones as $milestone) {
            // A copied milestone starts open: carrying "completed" across would claim work
            // was finished in a project that has not started.
            $created = Milestone::query()->create([
                'workspace_id' => $copy->workspace_id,
                'project_id' => $copy->getKey(),
                'name' => (string) $milestone->name,
                'description' => $milestone->description,
                'status' => $milestone->status === MilestoneStatus::Cancelled
                    ? MilestoneStatus::Cancelled
                    : MilestoneStatus::Planned,
                'start_date' => $this->date($milestone->start_date, $options),
                'due_date' => $this->date($milestone->due_date, $options),
                'completed_at' => null,
                'owner_id' => $options->assignees ? $milestone->owner_id : null,
                'position' => (int) $milestone->position,
                'progress' => 0,
            ]);

            $map[(int) $milestone->getKey()] = (int) $created->getKey();
        }

        return $map;
    }

    /**
     * @param array<int, int> $statusMap
     * @param array<int, int> $milestoneMap
     */
    private function copyTasks(
        Project $source,
        Project $copy,
        User $owner,
        DuplicateProjectOptions $options,
        array $statusMap,
        array $milestoneMap,
    ): int {
        $query = Task::query()
            ->withoutWorkspaceScope()
            ->where('project_id', $source->getKey())
            ->orderBy('id');

        if (! $options->completedTasks) {
            $completedStatusIds = TaskStatus::query()
                ->withoutWorkspaceScope()
                ->where('project_id', $source->getKey())
                ->where('is_completed', true)
                ->pluck('id')
                ->all();

            if ($completedStatusIds !== []) {
                $query->whereNotIn('status_id', $completedStatusIds);
            }
        }

        $tasks = $query->get();

        if ($tasks->isEmpty()) {
            return 0;
        }

        $fallbackStatusId = (int) (TaskStatus::query()
            ->withoutWorkspaceScope()
            ->where('project_id', $copy->getKey())
            ->orderByDesc('is_default')
            ->ordered()
            ->value('id') ?? 0);

        /** @var array<int, int> $taskMap */
        $taskMap = [];
        $number = 0;

        foreach ($tasks as $task) {
            $number++;
            $statusId = $statusMap[(int) $task->status_id] ?? $fallbackStatusId;

            $created = Task::query()->create([
                'workspace_id' => $copy->workspace_id,
                'project_id' => $copy->getKey(),
                'number' => $number,
                'title' => (string) $task->title,
                'description' => $task->description,
                'status_id' => $statusId,
                'priority' => $task->priority,
                'assignee_id' => $options->assignees ? $task->assignee_id : null,
                'reporter_id' => $owner->getKey(),
                'milestone_id' => $milestoneMap[(int) $task->milestone_id] ?? null,
                'start_date' => $this->date($task->start_date, $options),
                'due_date' => $this->date($task->due_date, $options),
                'completed_at' => null,
                'estimate_minutes' => $task->estimate_minutes,
                'position' => $task->position,
                'progress' => 0,
                'created_by' => $owner->getKey(),
                'ai_generated' => false,
            ]);

            $taskMap[(int) $task->getKey()] = (int) $created->getKey();

            if ($options->tags) {
                $tagIds = $task->tags()->pluck('tags.id')->all();

                if ($tagIds !== []) {
                    $created->tags()->syncWithoutDetaching($tagIds);
                }
            }

            if ($options->checklists) {
                $this->copyChecklist($task, $created);
            }
        }

        // Parents last: a subtask can be copied before the parent it points at.
        foreach ($tasks as $task) {
            if ($task->parent_id === null) {
                continue;
            }

            $newParentId = $taskMap[(int) $task->parent_id] ?? null;
            $newTaskId = $taskMap[(int) $task->getKey()] ?? null;

            if ($newParentId === null || $newTaskId === null) {
                continue;
            }

            Task::query()->withoutWorkspaceScope()->whereKey($newTaskId)->update(['parent_id' => $newParentId]);
        }

        // Batch numbering rather than {@see \App\Actions\Tasks\TaskNumbers}: the copy was
        // inserted in this transaction and nobody else can be creating tasks in it yet, so
        // the per-task row lock that allocator exists to take has nothing to serialise
        // against and would cost one locked read per copied task.
        $copy->forceFill(['task_number_seq' => $number])->save();

        return $number;
    }

    private function copyChecklist(Task $source, Task $copy): void
    {
        $items = TaskChecklistItem::query()
            ->where('task_id', $source->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            TaskChecklistItem::query()->create([
                'task_id' => $copy->getKey(),
                'title' => (string) $item->title,
                'is_done' => false,
                'position' => (int) $item->position,
            ]);
        }
    }

    private function copyMembers(Project $source, Project $copy): void
    {
        $members = ProjectMember::query()
            ->where('project_id', $source->getKey())
            ->get();

        foreach ($members as $member) {
            ProjectMember::query()->firstOrCreate(
                [
                    'project_id' => $copy->getKey(),
                    'user_id' => $member->user_id,
                ],
                ['role' => $member->role ?? ProjectRole::Member],
            );
        }
    }

    private function copyProjectTags(Project $source, Project $copy): void
    {
        $tagIds = $source->tags()->pluck('tags.id')->all();

        if ($tagIds !== []) {
            $copy->tags()->syncWithoutDetaching($tagIds);
        }
    }

    private function copySavedViews(Project $source, Project $copy): int
    {
        // Only the project's own views travel. A personal view is somebody's private filter
        // on a project they may not even be a member of any more.
        $views = SavedView::query()
            ->withoutWorkspaceScope()
            ->where('project_id', $source->getKey())
            ->where(static function (Builder $query): void {
                $query->whereNull('user_id')->orWhere('is_shared', true);
            })
            ->ordered()
            ->get();

        foreach ($views as $view) {
            SavedView::query()->create([
                'workspace_id' => $copy->workspace_id,
                'project_id' => $copy->getKey(),
                'user_id' => null,
                'name' => (string) $view->name,
                'type' => $view->type,
                'filters' => is_array($view->filters) ? $view->filters : [],
                'sorts' => $view->sorts,
                'columns' => $view->columns,
                'group_by' => $view->group_by,
                'is_shared' => true,
                'is_pinned' => (bool) $view->is_pinned,
                'position' => (int) $view->position,
            ]);
        }

        return $views->count();
    }

    private function date(?Carbon $date, DuplicateProjectOptions $options): ?Carbon
    {
        if ($date === null || $options->resetDates) {
            return null;
        }

        return $options->shiftDays === null ? $date->copy() : $date->copy()->addDays($options->shiftDays);
    }
}
