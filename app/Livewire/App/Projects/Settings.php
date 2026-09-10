<?php

declare(strict_types=1);

namespace App\Livewire\App\Projects;

use App\Actions\CustomFields\CreateCustomField;
use App\Actions\CustomFields\DeleteCustomField;
use App\Actions\CustomFields\UpdateCustomField;
use App\Actions\Members\AddProjectMember;
use App\Actions\Members\ChangeProjectMemberRole;
use App\Actions\Members\InviteMember;
use App\Actions\Members\RemoveProjectMember;
use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\ProjectAttributes;
use App\Actions\Projects\ProjectKeyGenerator;
use App\Actions\Projects\RestoreProject;
use App\Actions\Projects\UpdateProject;
use App\Actions\Tags\CreateTag;
use App\Actions\Tags\DeleteTag;
use App\Actions\Tags\TagChanges;
use App\Actions\Tags\UpdateTag;
use App\Enums\CustomFieldType;
use App\Enums\Priority;
use App\Enums\ProjectHealth;
use App\Enums\ProjectRole;
use App\Enums\ProjectType;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Exceptions\DomainException;
use App\Livewire\App\Projects\Concerns\InteractsWithProjectShell;
use App\Models\CustomField;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Tag;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectHealthCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Everything about a project that is configuration rather than work.
 *
 * One component with six sections rather than six components, because they share the project,
 * the shell and the permission set, and because a settings screen where each pane is its own
 * round trip feels like a control panel rather than a product.
 *
 * The destructive end of it is deliberately the slowest thing here: archiving states what it
 * does and does not remove, and deleting states the blast radius in counts and refuses to
 * proceed until the project key is typed out. A confirmation you can dismiss by reflex is
 * not a confirmation.
 */
#[Layout('layouts.app')]
final class Settings extends Component
{
    use InteractsWithProjectShell;

    /** The badge palette a status colour is chosen from — see resources/views/components/ui/badge.blade.php. */
    public const STATUS_COLORS = ['gray', 'brand', 'blue', 'teal', 'green', 'amber', 'orange', 'red', 'purple', 'pink'];

    /** @var list<string> */
    public const SECTIONS = ['general', 'statuses', 'members', 'tags', 'fields', 'danger'];

    public Workspace $workspace;

    public Project $project;

    /** general · statuses · members · tags · fields · danger */
    #[Url(except: 'general')]
    public string $section = 'general';

    /* -------------------------------------------------- General ------- */

    public string $name = '';

    public string $key = '';

    public string $description = '';

    public string $type = 'general';

    public string $priority = 'medium';

    public ?int $statusId = null;

    public string $color = '#3F66B0';

    public string $icon = '';

    public string $clientName = '';

    public string $department = '';

    public string $startDate = '';

    public string $targetDate = '';

    public string $budget = '';

    public string $currency = 'USD';

    public ?int $managerId = null;

    /** auto · manual */
    public string $healthMode = 'auto';

    public string $health = 'on_track';

    public string $healthNote = '';

    /* ------------------------------------------------- Statuses ------- */

    public ?int $editingStatusId = null;

    public string $statusName = '';

    public string $statusCategory = 'todo';

    public string $statusColor = 'gray';

    /* -------------------------------------------------- Members ------- */

    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    public ?int $addMemberId = null;

    /* ----------------------------------------------------- Tags ------- */

    public ?int $editingTagId = null;

    public string $tagName = '';

    public string $tagColor = 'gray';

    /* --------------------------------------------- Custom fields ------ */

    public ?int $editingFieldId = null;

    public string $fieldName = '';

    public string $fieldType = 'text';

    public string $fieldOptions = '';

    public bool $fieldRequired = false;

    /* --------------------------------------------------- Danger ------- */

    public string $deleteConfirmation = '';

    public function mount(Workspace $workspace, Project $project): void
    {
        $this->authorize('view', $project);
        $this->authorize('update', $project);

        $this->workspace = $workspace;
        $this->project = $project;

        // `section` comes off the query string, so it is whatever somebody typed. An
        // unrecognised value would render a settings screen with no settings on it.
        if (! in_array($this->section, self::SECTIONS, true)) {
            $this->section = 'general';
        }

        $this->fillGeneralFromProject();
    }

    public function updatedSection(string $value): void
    {
        if (! in_array($value, self::SECTIONS, true)) {
            $this->section = 'general';
        }
    }

    /* ================================================================== *
     * General
     * ================================================================== */

    public function saveGeneral(UpdateProject $updateProject): void
    {
        $this->authorize('update', $this->project);

        $data = $this->validate($this->generalRules(), [], $this->generalAttributes());

        $attributes = new ProjectAttributes(
            name: $data['name'],
            key: $data['key'],
            description: $data['description'] ?? '',
            icon: $data['icon'] ?? '',
            color: $data['color'],
            type: ProjectType::from($data['type']),
            statusId: $this->statusId,
            health: $this->healthMode === 'manual' ? ProjectHealth::from($data['health']) : null,
            healthNote: $data['healthNote'] ?? '',
            priority: Priority::from($data['priority']),
            managerId: $this->managerId,
            clientName: $data['clientName'] ?? '',
            department: $data['department'] ?? '',
            startDate: $data['startDate'] === '' ? null : $data['startDate'],
            targetDate: $data['targetDate'] === '' ? null : $data['targetDate'],
            budget: $data['budget'] === '' ? null : $data['budget'],
            currency: $data['currency'],
        );

        try {
            $updateProject($this->project, $attributes, auth()->user());
        } catch (DomainException $exception) {
            $this->addError('key', $exception->userMessage());

            return;
        }

        $this->releaseManualHealth();

        $this->project->refresh();
        $this->fillGeneralFromProject();
        unset($this->isFavourite);

        $this->dispatch('planvio-notify', type: 'success', message: __('Project settings saved.'));
    }

    /**
     * Hand health back to the calculator.
     *
     * {@see UpdateProject} latches `health_set_manually` when a health value is passed and
     * never unlatches it — deliberately, because that flag is what protects a human judgement
     * from being overwritten. Releasing it is therefore a separate, explicit act, and the
     * calculator immediately writes what it actually measures so the badge never sits on a
     * stale hand-set value.
     */
    private function releaseManualHealth(): void
    {
        if ($this->healthMode !== 'auto' || ! $this->project->health_set_manually) {
            return;
        }

        $this->project->health_set_manually = false;
        Project::withoutTimestamps(fn () => $this->project->save());

        app(ProjectHealthCalculator::class)->apply($this->project);
    }

    /* ================================================================== *
     * Statuses
     * ================================================================== */

    public function addStatus(): void
    {
        $this->authorize('create', [TaskStatus::class, $this->project]);

        $data = $this->validate([
            'statusName' => ['required', 'string', 'min:1', 'max:255'],
            'statusCategory' => ['required', Rule::enum(StatusCategory::class)],
            'statusColor' => ['required', Rule::in(self::STATUS_COLORS)],
        ], [], ['statusName' => __('column name')]);

        $category = StatusCategory::from($data['statusCategory']);

        TaskStatus::query()->create([
            'workspace_id' => $this->project->workspace_id,
            'project_id' => $this->project->getKey(),
            'name' => $data['statusName'],
            'color' => $data['statusColor'],
            'category' => $category,
            'position' => (int) $this->statuses->max('position') + 1,
            'is_default' => false,
            // A column in a closed category stops the clock on the tasks in it, which is
            // what drives completion, progress and the overdue counts.
            'is_completed' => $category->isClosed(),
        ]);

        $this->reset(['statusName', 'statusCategory', 'statusColor']);
        unset($this->statuses);

        $this->dispatch('planvio-notify', type: 'success', message: __('Column added.'));
    }

    public function editStatus(int $statusId): void
    {
        $status = $this->requireStatus($statusId);

        $this->authorize('update', $status);

        $this->editingStatusId = (int) $status->getKey();
        $this->statusName = (string) $status->name;
        $this->statusCategory = $status->category->value;
        $this->statusColor = in_array((string) $status->color, self::STATUS_COLORS, true)
            ? (string) $status->color
            : 'gray';
    }

    public function cancelStatusEdit(): void
    {
        $this->reset(['editingStatusId', 'statusName', 'statusCategory', 'statusColor']);
        $this->resetErrorBag();
    }

    public function saveStatus(): void
    {
        if ($this->editingStatusId === null) {
            return;
        }

        $status = $this->requireStatus($this->editingStatusId);

        $this->authorize('update', $status);

        $data = $this->validate([
            'statusName' => ['required', 'string', 'min:1', 'max:255'],
            'statusCategory' => ['required', Rule::enum(StatusCategory::class)],
            'statusColor' => ['required', Rule::in(self::STATUS_COLORS)],
        ], [], ['statusName' => __('column name')]);

        $category = StatusCategory::from($data['statusCategory']);

        $status->fill([
            'name' => $data['statusName'],
            'color' => $data['statusColor'],
            'category' => $category,
            'is_completed' => $category->isClosed(),
        ])->save();

        $this->cancelStatusEdit();
        unset($this->statuses);

        $this->dispatch('planvio-notify', type: 'success', message: __('Column updated.'));
    }

    public function makeStatusDefault(int $statusId): void
    {
        $status = $this->requireStatus($statusId);

        $this->authorize('update', $status);

        // Exactly one landing column, always: with none a new task has nowhere to go, and
        // with several the winner would depend on row order.
        DB::transaction(function () use ($status): void {
            TaskStatus::query()
                ->where('project_id', $this->project->getKey())
                ->update(['is_default' => false]);

            $status->forceFill(['is_default' => true])->save();
        });

        unset($this->statuses);

        $this->dispatch('planvio-notify', type: 'success', message: __('New tasks will land in “:name”.', [
            'name' => $status->name,
        ]));
    }

    public function deleteStatus(int $statusId): void
    {
        $status = $this->requireStatus($statusId);

        $this->authorize('delete', $status);

        if ($this->statuses->count() <= 1) {
            $this->dispatch('planvio-notify', type: 'error', message: __('A board needs at least one column.'));

            return;
        }

        $taskCount = (int) $status->tasks()->count();

        if ($taskCount > 0) {
            // Deleting would orphan them, and silently moving somebody's tasks is worse than
            // refusing: they may be in review for a reason.
            $this->dispatch('planvio-notify', type: 'error', message: trans_choice(
                '{1}“:name” still holds 1 task. Move it to another column first.'
                .'|[2,*]“:name” still holds :count tasks. Move them to another column first.',
                $taskCount,
                ['name' => $status->name, 'count' => $taskCount],
            ));

            return;
        }

        $wasDefault = (bool) $status->is_default;
        $status->delete();

        if ($wasDefault) {
            unset($this->statuses);
            $replacement = $this->statuses->first();

            if ($replacement !== null) {
                $replacement->forceFill(['is_default' => true])->save();
            }
        }

        unset($this->statuses);

        $this->dispatch('planvio-notify', type: 'success', message: __('Column removed.'));
    }

    /**
     * Called by the `sortableList` Alpine component with the ids in their new order.
     *
     * @param list<int> $ids
     */
    public function reorderStatuses(array $ids): void
    {
        $this->authorize('update', $this->project);

        $statuses = $this->statuses->keyBy(fn (TaskStatus $status): int => (int) $status->getKey());

        DB::transaction(function () use ($ids, $statuses): void {
            $position = 0;

            foreach ($ids as $id) {
                $status = $statuses->get((int) $id);

                // An id the browser sent that is not one of this project's columns is
                // skipped rather than trusted: the payload is client input.
                if ($status === null) {
                    continue;
                }

                $status->forceFill(['position' => $position])->save();
                $position++;
            }
        });

        unset($this->statuses);

        $this->dispatch('planvio-notify', type: 'success', message: __('Board order saved.'));
    }

    /* ================================================================== *
     * Members
     * ================================================================== */

    public function addMember(AddProjectMember $addProjectMember): void
    {
        $this->authorize('manageMembers', $this->project);

        $data = $this->validate([
            'addMemberId' => ['required', 'integer', Rule::in($this->addableIds())],
        ], [], ['addMemberId' => __('person')]);

        $user = User::query()->findOrFail($data['addMemberId']);

        $addProjectMember($this->project, $user, ProjectRole::Member, auth()->user());

        $this->reset('addMemberId');
        $this->forgetMemberCaches();

        $this->dispatch('planvio-notify', type: 'success', message: __(':name joined the project.', [
            'name' => $user->name,
        ]));
    }

    public function changeMemberRole(
        int $userId,
        string $role,
        ChangeProjectMemberRole $changeProjectMemberRole,
    ): void {
        $this->authorize('manageMembers', $this->project);

        $projectRole = ProjectRole::tryFrom($role);
        $user = $this->members->firstWhere('id', $userId);

        if ($projectRole === null || $user === null) {
            return;
        }

        if ($projectRole !== ProjectRole::Manager && $this->wouldLeaveNoManager($userId)) {
            $this->dispatch('planvio-notify', type: 'error', message: __('A project keeps at least one manager.'));

            return;
        }

        try {
            $changeProjectMemberRole($this->project, $user, $projectRole, auth()->user());
        } catch (DomainException $exception) {
            $this->dispatch('planvio-notify', type: 'error', message: $exception->userMessage());

            return;
        }

        $this->forgetMemberCaches();

        $this->dispatch('planvio-notify', type: 'success', message: __(':name is now a :role.', [
            'name' => $user->name,
            'role' => mb_strtolower($projectRole->label()),
        ]));
    }

    public function removeMember(int $userId, RemoveProjectMember $removeProjectMember): void
    {
        $this->authorize('manageMembers', $this->project);

        $user = $this->members->firstWhere('id', $userId);

        if ($user === null) {
            return;
        }

        if ((int) $this->project->owner_id === $userId) {
            $this->dispatch('planvio-notify', type: 'error', message: __('The project owner cannot be removed. Hand the project over first.'));

            return;
        }

        if ($this->wouldLeaveNoManager($userId)) {
            $this->dispatch('planvio-notify', type: 'error', message: __('A project keeps at least one manager.'));

            return;
        }

        // Open work follows the project manager rather than being dropped: a guest who loses
        // access cannot finish the task either. `loadMissing` rather than a bare relation
        // read — Livewire rehydrates the model from its key, so nothing is loaded yet and
        // Model::preventLazyLoading() would (rightly) throw.
        $this->project->loadMissing('manager');

        $removeProjectMember($this->project, $user, $this->project->manager, auth()->user());

        $this->forgetMemberCaches();

        $this->dispatch('planvio-notify', type: 'success', message: __(':name was removed from the project.', [
            'name' => $user->name,
        ]));
    }

    public function invite(InviteMember $inviteMember): void
    {
        $this->authorize('manageMembers', $this->project);
        $this->authorize('create', [Invitation::class, $this->project]);

        $data = $this->validate([
            'inviteEmail' => ['required', 'email', 'max:255'],
            'inviteRole' => ['required', Rule::enum(ProjectRole::class)],
        ], [], ['inviteEmail' => __('e-mail address')]);

        // A project invitation is a workspace invitation scoped to this project. Someone
        // invited as a project guest gets the workspace guest role, which on its own grants
        // nothing outside the projects they were named in.
        $workspaceRole = ProjectRole::from($data['inviteRole']) === ProjectRole::Guest
            ? WorkspaceRole::Guest
            : WorkspaceRole::Member;

        try {
            $inviteMember(
                $this->workspace,
                auth()->user(),
                $data['inviteEmail'],
                $workspaceRole,
                $this->project,
            );
        } catch (DomainException $exception) {
            $this->addError('inviteEmail', $exception->userMessage());

            return;
        }

        $this->reset('inviteEmail');
        unset($this->invitations);

        $this->dispatch('planvio-notify', type: 'success', message: __('Invitation sent to :email.', [
            'email' => $data['inviteEmail'],
        ]));
    }

    /* ================================================================== *
     * Tags
     * ================================================================== */

    public function addTag(CreateTag $createTag): void
    {
        $this->authorize('create', [Tag::class, $this->workspace]);

        $data = $this->validate([
            'tagName' => ['required', 'string', 'min:1', 'max:255'],
            'tagColor' => ['required', Rule::in(self::STATUS_COLORS)],
        ], [], ['tagName' => __('tag name')]);

        try {
            $createTag($this->workspace, $data['tagName'], auth()->user(), $data['tagColor']);
        } catch (DomainException $exception) {
            $this->addError('tagName', $exception->userMessage());

            return;
        }

        $this->reset(['tagName', 'tagColor']);
        unset($this->tags);

        $this->dispatch('planvio-notify', type: 'success', message: __('Tag created.'));
    }

    public function editTag(int $tagId): void
    {
        $tag = $this->requireTag($tagId);

        $this->authorize('update', $tag);

        $this->editingTagId = (int) $tag->getKey();
        $this->tagName = (string) $tag->name;
        $this->tagColor = in_array((string) $tag->color, self::STATUS_COLORS, true) ? (string) $tag->color : 'gray';
    }

    public function cancelTagEdit(): void
    {
        $this->reset(['editingTagId', 'tagName', 'tagColor']);
        $this->resetErrorBag();
    }

    public function saveTag(UpdateTag $updateTag): void
    {
        if ($this->editingTagId === null) {
            return;
        }

        $tag = $this->requireTag($this->editingTagId);

        $this->authorize('update', $tag);

        $data = $this->validate([
            'tagName' => ['required', 'string', 'min:1', 'max:255'],
            'tagColor' => ['required', Rule::in(self::STATUS_COLORS)],
        ], [], ['tagName' => __('tag name')]);

        try {
            $updateTag(
                $tag,
                TagChanges::make()->name($data['tagName'])->color($data['tagColor']),
                auth()->user(),
            );
        } catch (DomainException $exception) {
            $this->addError('tagName', $exception->userMessage());

            return;
        }

        $this->cancelTagEdit();
        unset($this->tags);

        $this->dispatch('planvio-notify', type: 'success', message: __('Tag updated.'));
    }

    public function deleteTag(int $tagId, DeleteTag $deleteTag): void
    {
        $tag = $this->requireTag($tagId);

        $this->authorize('delete', $tag);

        $deleteTag($tag, auth()->user());

        $this->cancelTagEdit();
        unset($this->tags);

        $this->dispatch('planvio-notify', type: 'success', message: __('Tag deleted.'));
    }

    /* ================================================================== *
     * Custom fields
     * ================================================================== */

    public function addField(CreateCustomField $createCustomField): void
    {
        $this->authorize('create', [CustomField::class, $this->project]);

        $data = $this->validate($this->fieldRules(), [], ['fieldName' => __('field name')]);

        $type = CustomFieldType::from($data['fieldType']);

        try {
            $createCustomField(
                $this->workspace,
                auth()->user(),
                $data['fieldName'],
                $type,
                null,
                $this->project,
                CustomField::ENTITY_TASK,
                $type->hasOptions() ? $this->parsedOptions() : null,
                $this->fieldRequired,
            );
        } catch (DomainException $exception) {
            $this->addError('fieldName', $exception->userMessage());

            return;
        }

        $this->reset(['fieldName', 'fieldType', 'fieldOptions', 'fieldRequired']);
        unset($this->fields);

        $this->dispatch('planvio-notify', type: 'success', message: __('Field added.'));
    }

    public function editField(int $fieldId): void
    {
        $field = $this->requireField($fieldId);

        $this->authorize('update', $field);

        $this->editingFieldId = (int) $field->getKey();
        $this->fieldName = (string) $field->name;
        $this->fieldType = $field->type->value;
        $this->fieldRequired = (bool) $field->is_required;
        $this->fieldOptions = implode("\n", array_map('strval', (array) ($field->options ?? [])));
    }

    public function cancelFieldEdit(): void
    {
        $this->reset(['editingFieldId', 'fieldName', 'fieldType', 'fieldOptions', 'fieldRequired']);
        $this->resetErrorBag();
    }

    public function saveField(UpdateCustomField $updateCustomField): void
    {
        if ($this->editingFieldId === null) {
            return;
        }

        $field = $this->requireField($this->editingFieldId);

        $this->authorize('update', $field);

        $data = $this->validate($this->fieldRules(), [], ['fieldName' => __('field name')]);

        $type = CustomFieldType::from($data['fieldType']);

        try {
            $updateCustomField(
                $field,
                auth()->user(),
                $data['fieldName'],
                $type,
                $type->hasOptions() ? $this->parsedOptions() : null,
                $this->fieldRequired,
                null,
                true,
            );
        } catch (DomainException $exception) {
            $this->addError('fieldName', $exception->userMessage());

            return;
        }

        $this->cancelFieldEdit();
        unset($this->fields);

        $this->dispatch('planvio-notify', type: 'success', message: __('Field updated.'));
    }

    public function deleteField(int $fieldId, DeleteCustomField $deleteCustomField): void
    {
        $field = $this->requireField($fieldId);

        $this->authorize('delete', $field);

        $deleteCustomField($field, auth()->user());

        $this->cancelFieldEdit();
        unset($this->fields);

        $this->dispatch('planvio-notify', type: 'success', message: __('Field deleted.'));
    }

    /* ================================================================== *
     * Danger zone
     * ================================================================== */

    public function archive(ArchiveProject $archiveProject): void
    {
        $this->authorize('archive', $this->project);

        $archiveProject($this->project, auth()->user());

        $this->project->refresh();

        $this->dispatch('planvio-notify', type: 'success', message: __('“:project” is archived. Nothing was deleted.', [
            'project' => $this->project->name,
        ]));
    }

    public function restore(RestoreProject $restoreProject): void
    {
        $this->authorize('unarchive', $this->project);

        try {
            $restoreProject($this->project, auth()->user());
        } catch (DomainException $exception) {
            $this->dispatch('planvio-notify', type: 'error', message: $exception->userMessage());

            return;
        }

        $this->project->refresh();

        $this->dispatch('planvio-notify', type: 'success', message: __('“:project” is active again.', [
            'project' => $this->project->name,
        ]));
    }

    public function destroy(DeleteProject $deleteProject): void
    {
        $this->authorize('delete', $this->project);

        // The key, typed out. It is short, it is on screen, and it is specific to this
        // project — which is exactly what a confirmation has to be to mean anything.
        if (mb_strtoupper(trim($this->deleteConfirmation)) !== mb_strtoupper((string) $this->project->key)) {
            $this->addError('deleteConfirmation', __('Type :key exactly to confirm.', [
                'key' => $this->project->display_key,
            ]));

            return;
        }

        $deleteProject($this->project, auth()->user());

        $this->redirectRoute('app.projects.index', $this->workspace);
    }

    /* ================================================================== *
     * Reads
     * ================================================================== */

    /**
     * @return EloquentCollection<int, TaskStatus>
     */
    #[Computed]
    public function statuses(): EloquentCollection
    {
        return $this->project->taskStatuses()
            ->withCount('tasks')
            ->ordered()
            ->get();
    }

    /**
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function members(): EloquentCollection
    {
        return $this->project->members()
            ->select(['users.id', 'users.name', 'users.email', 'users.avatar_path', 'users.job_title'])
            ->orderByRaw("case project_members.role when 'manager' then 0 when 'member' then 1 else 2 end")
            ->orderBy('users.name')
            ->get();
    }

    /**
     * Workspace members who are not on the project yet.
     *
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function addableMembers(): EloquentCollection
    {
        $existing = $this->members->modelKeys();

        return User::query()
            ->select(['users.id', 'users.name', 'users.email'])
            ->join('workspace_members', 'workspace_members.user_id', '=', 'users.id')
            ->where('workspace_members.workspace_id', $this->workspace->getKey())
            ->when($existing !== [], fn ($query) => $query->whereNotIn('users.id', $existing))
            ->orderBy('users.name')
            ->get();
    }

    /**
     * Invitations still outstanding for this project.
     *
     * @return EloquentCollection<int, Invitation>
     */
    #[Computed]
    public function invitations(): EloquentCollection
    {
        if (! Gate::allows('viewAny', [Invitation::class, $this->project])) {
            return new EloquentCollection;
        }

        return Invitation::query()
            ->where('invitations.workspace_id', $this->workspace->getKey())
            ->where('invitations.project_id', $this->project->getKey())
            ->whereNull('invitations.accepted_at')
            ->orderByDesc('invitations.created_at')
            ->limit(25)
            ->get();
    }

    /**
     * @return EloquentCollection<int, Tag>
     */
    #[Computed]
    public function tags(): EloquentCollection
    {
        if (! Gate::allows('viewAny', [Tag::class, $this->workspace])) {
            return new EloquentCollection;
        }

        return Tag::query()
            ->where('tags.workspace_id', $this->workspace->getKey())
            ->withCount('tasks')
            ->orderBy('tags.name')
            ->get();
    }

    /**
     * @return EloquentCollection<int, CustomField>
     */
    #[Computed]
    public function fields(): EloquentCollection
    {
        if (! Gate::allows('viewAny', [CustomField::class, $this->project])) {
            return new EloquentCollection;
        }

        return CustomField::query()
            ->where('custom_fields.workspace_id', $this->workspace->getKey())
            ->availableForProject($this->project)
            ->forEntity(CustomField::ENTITY_TASK)
            ->ordered()
            ->get();
    }

    /**
     * @return EloquentCollection<int, ProjectStatus>
     */
    #[Computed]
    public function projectStatuses(): EloquentCollection
    {
        return ProjectStatus::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->select(['id', 'name', 'color', 'category', 'position'])
            ->ordered()
            ->get();
    }

    /**
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function managers(): EloquentCollection
    {
        return User::query()
            ->select(['users.id', 'users.name'])
            ->join('workspace_members', 'workspace_members.user_id', '=', 'users.id')
            ->where('workspace_members.workspace_id', $this->workspace->getKey())
            ->where('workspace_members.role', '!=', WorkspaceRole::Guest->value)
            ->orderBy('users.name')
            ->get();
    }

    /**
     * What deleting would take with it, as counts rather than as a warning triangle.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function blastRadius(): array
    {
        return [
            'tasks' => (int) $this->project->tasks()->count(),
            'milestones' => (int) $this->project->milestones()->count(),
            'wiki_pages' => (int) $this->project->wikiPages()->count(),
            'time_entries' => (int) $this->project->timeEntries()->count(),
            'expenses' => (int) $this->project->expenses()->count(),
            'attachments' => (int) $this->project->attachments()->count(),
            'members' => (int) $this->project->projectMembers()->count(),
        ];
    }

    public function render(): View
    {
        $this->project->loadMissing([
            'status:id,name,color,category',
            'owner:id,name,avatar_path',
            'manager:id,name,avatar_path',
        ]);

        return view('livewire.app.projects.settings')
            ->title($this->project->name.' · '.__('Settings'));
    }

    /* ================================================================== *
     * Internals
     * ================================================================== */

    private function fillGeneralFromProject(): void
    {
        $project = $this->project;

        $this->name = (string) $project->name;
        $this->key = (string) $project->key;
        $this->description = (string) ($project->description ?? '');
        $this->type = $project->type->value;
        $this->priority = $project->priority->value;
        $this->statusId = $project->status_id === null ? null : (int) $project->status_id;
        $this->color = (string) $project->color;
        $this->icon = (string) ($project->icon ?? '');
        $this->clientName = (string) ($project->client_name ?? '');
        $this->department = (string) ($project->department ?? '');
        $this->startDate = $project->start_date?->toDateString() ?? '';
        $this->targetDate = $project->target_date?->toDateString() ?? '';
        $this->budget = $project->budget === null ? '' : (string) $project->budget;
        $this->currency = (string) ($project->currency ?: $this->workspace->currency ?: 'USD');
        $this->managerId = $project->manager_id === null ? null : (int) $project->manager_id;
        $this->healthMode = $project->health_set_manually ? 'manual' : 'auto';
        $this->health = $project->health->value;
        $this->healthNote = (string) ($project->health_note ?? '');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function generalRules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'key' => [
                'required',
                'string',
                'max:'.ProjectKeyGenerator::MAX_LENGTH,
                'regex:/^[A-Za-z][A-Za-z0-9]*$/',
                // The unique index covers soft-deleted rows, so the rule does too: a
                // restored project must not collide with a key taken while it was away.
                Rule::unique('projects', 'key')
                    ->ignore($this->project->getKey())
                    ->where(fn ($query) => $query->where('workspace_id', $this->workspace->getKey())),
            ],
            'description' => ['nullable', 'string', 'max:20000'],
            'type' => ['required', Rule::enum(ProjectType::class)],
            'priority' => ['required', Rule::enum(Priority::class)],
            'statusId' => ['nullable', 'integer', Rule::in($this->projectStatuses->modelKeys())],
            'color' => ['required', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'icon' => ['nullable', 'string', 'max:16'],
            'clientName' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'startDate' => ['nullable', 'date'],
            'targetDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'managerId' => ['nullable', 'integer', Rule::in($this->managers->modelKeys())],
            'healthMode' => ['required', Rule::in(['auto', 'manual'])],
            'health' => ['required', Rule::enum(ProjectHealth::class)],
            'healthNote' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function generalAttributes(): array
    {
        return [
            'name' => __('name'),
            'key' => __('key'),
            'statusId' => __('stage'),
            'startDate' => __('start date'),
            'targetDate' => __('target date'),
            'managerId' => __('project manager'),
            'healthNote' => __('health note'),
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function fieldRules(): array
    {
        return [
            'fieldName' => ['required', 'string', 'min:1', 'max:255'],
            'fieldType' => ['required', Rule::enum(CustomFieldType::class)],
            'fieldOptions' => ['nullable', 'string', 'max:5000'],
            'fieldRequired' => ['boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    private function parsedOptions(): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $this->fieldOptions) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
    }

    /**
     * @return list<int>
     */
    private function addableIds(): array
    {
        return $this->addableMembers->modelKeys();
    }

    /**
     * A project with no manager is a project nobody can configure: `project.update` is a `+`
     * cell, granted to a workspace manager only inside projects they manage.
     */
    private function wouldLeaveNoManager(int $userId): bool
    {
        $managerIds = $this->project->projectMembers()
            ->where('role', ProjectRole::Manager->value)
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $managerIds === [$userId];
    }

    private function requireStatus(int $statusId): TaskStatus
    {
        $status = $this->statuses->firstWhere('id', $statusId);

        abort_if($status === null, 404);

        return $status;
    }

    private function requireTag(int $tagId): Tag
    {
        $tag = $this->tags->firstWhere('id', $tagId);

        abort_if($tag === null, 404);

        return $tag;
    }

    private function requireField(int $fieldId): CustomField
    {
        $field = $this->fields->firstWhere('id', $fieldId);

        abort_if($field === null, 404);

        return $field;
    }

    private function forgetMemberCaches(): void
    {
        unset($this->members, $this->addableMembers, $this->blastRadius);
    }
}
