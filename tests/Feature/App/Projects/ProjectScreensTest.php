<?php

declare(strict_types=1);

namespace Tests\Feature\App\Projects;

use App\Enums\CustomFieldType;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectRole;
use App\Enums\ProjectType;
use App\Enums\WorkspaceRole;
use App\Livewire\App\Projects\Create as ProjectCreate;
use App\Livewire\App\Projects\Index as ProjectIndex;
use App\Livewire\App\Projects\Settings as ProjectSettings;
use App\Livewire\App\Projects\Show as ProjectShow;
use App\Models\Attachment;
use App\Models\CustomField;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProjectScreensTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Owner);
        $this->project = $this->makeProject(
            $this->workspace,
            [[$this->member, ProjectRole::Manager]],
            ['slug' => 'website', 'key' => 'WEB', 'owner_id' => $this->member->getKey()],
        );
        $this->makeTask($this->project);
    }

    /* ---------------------------------------------------------------- *
     * Rendering
     * ---------------------------------------------------------------- */

    #[Test]
    public function the_index_renders(): void
    {
        $this->actingAs($this->member)
            ->get(route('app.projects.index', $this->workspace))
            ->assertOk()
            ->assertSee('Projects');
    }

    #[Test]
    public function the_index_renders_as_a_list(): void
    {
        $this->actingAs($this->member)
            ->withSession(['planvio.projects.display' => 'list'])
            ->get(route('app.projects.index', $this->workspace))
            ->assertOk();
    }

    #[Test]
    public function the_index_renders_empty(): void
    {
        $empty = $this->makeWorkspace(['slug' => 'empty-ws']);
        $owner = $this->makeMember($empty, WorkspaceRole::Owner);

        $this->actingAs($owner)
            ->get(route('app.projects.index', $empty))
            ->assertOk()
            ->assertSee('No projects yet');
    }

    #[Test]
    public function the_create_screen_renders(): void
    {
        $this->actingAs($this->member)
            ->get(route('app.projects.create', $this->workspace))
            ->assertOk();
    }

    #[Test]
    public function the_overview_renders_with_a_full_project(): void
    {
        $this->fillProject();

        $this->actingAs($this->member)
            ->get(route('app.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertSee('Overview')
            ->assertSee('Risks');
    }

    #[Test]
    public function every_settings_section_renders(): void
    {
        $this->fillProject();

        foreach (['general', 'statuses', 'members', 'tags', 'fields', 'danger'] as $section) {
            $this->actingAs($this->member)
                ->get(route('app.projects.settings', [$this->workspace, $this->project]).'?section='.$section)
                ->assertOk();
        }
    }

    /* ---------------------------------------------------------------- *
     * Creation
     * ---------------------------------------------------------------- */

    #[Test]
    public function a_project_can_be_created_from_the_form(): void
    {
        Livewire::actingAs($this->member)
            ->test(ProjectCreate::class, ['workspace' => $this->workspace])
            ->call('startBlank')
            ->set('name', 'Marketing Site')
            ->assertSet('key', 'MS')
            ->set('color', '#3F66B0')
            ->set('targetDate', now()->addMonth()->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('projects', ['key' => 'MS', 'name' => 'Marketing Site']);
    }

    #[Test]
    public function a_duplicate_key_is_refused(): void
    {
        Livewire::actingAs($this->member)
            ->test(ProjectCreate::class, ['workspace' => $this->workspace])
            ->call('startBlank')
            ->set('name', 'Another')
            ->set('key', 'WEB')
            ->call('save')
            ->assertHasErrors('key');
    }

    #[Test]
    public function a_project_can_be_created_from_a_template(): void
    {
        $template = ProjectTemplate::query()->create([
            'workspace_id' => null,
            'name' => 'Website Project',
            'slug' => 'website-project-x',
            'description' => 'Ship a website.',
            'icon' => '🌐',
            'color' => '#3F66B0',
            'type' => ProjectType::Creative,
            'is_system' => true,
            'is_active' => true,
            'definition' => [
                'statuses' => [['name' => 'To Do', 'color' => 'gray', 'category' => 'todo', 'is_default' => true]],
                'milestones' => [['ref' => 'm1', 'name' => 'Launch', 'due_offset_days' => 30]],
                'tasks' => [['ref' => 't1', 'title' => 'Write the brief', 'milestone' => 'm1']],
            ],
        ]);

        Livewire::actingAs($this->member)
            ->test(ProjectCreate::class, ['workspace' => $this->workspace])
            ->call('startFromTemplate', $template->getKey())
            ->assertSet('name', 'Website Project')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('milestones', ['name' => 'Launch']);
    }

    #[Test]
    public function members_chosen_at_creation_are_added(): void
    {
        $colleague = $this->makeMember($this->workspace);

        Livewire::actingAs($this->member)
            ->test(ProjectCreate::class, ['workspace' => $this->workspace])
            ->call('startBlank')
            ->set('name', 'Team Project')
            ->set('color', '#3F66B0')
            ->call('toggleMember', $colleague->getKey())
            ->call('save')
            ->assertHasNoErrors();

        $created = Project::query()->where('name', 'Team Project')->firstOrFail();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $created->getKey(),
            'user_id' => $colleague->getKey(),
            'role' => ProjectRole::Member->value,
        ]);
    }

    /* ---------------------------------------------------------------- *
     * Index behaviour
     * ---------------------------------------------------------------- */

    #[Test]
    public function the_favourite_star_toggles_from_the_index(): void
    {
        Livewire::actingAs($this->member)
            ->test(ProjectIndex::class, ['workspace' => $this->workspace])
            ->call('toggleFavouriteFor', $this->project->getKey());

        $this->assertDatabaseHas('favorites', [
            'user_id' => $this->member->getKey(),
            'favoritable_id' => $this->project->getKey(),
        ]);
    }

    #[Test]
    public function a_project_from_another_workspace_cannot_be_starred(): void
    {
        $other = $this->makeWorkspace(['slug' => 'northwind']);
        $foreign = $this->makeProject($other);

        Livewire::actingAs($this->member)
            ->test(ProjectIndex::class, ['workspace' => $this->workspace])
            ->call('toggleFavouriteFor', $foreign->getKey())
            ->assertStatus(404);

        $this->assertDatabaseMissing('favorites', ['favoritable_id' => $foreign->getKey()]);
    }

    #[Test]
    public function filters_narrow_the_list(): void
    {
        $this->makeProject($this->workspace, [], ['name' => 'Zebra', 'key' => 'ZEB', 'slug' => 'zebra']);

        Livewire::actingAs($this->member)
            ->test(ProjectIndex::class, ['workspace' => $this->workspace])
            ->set('search', 'Zebra')
            ->assertSee('Zebra')
            ->assertDontSee($this->project->name);
    }

    /* ---------------------------------------------------------------- *
     * Settings behaviour
     * ---------------------------------------------------------------- */

    #[Test]
    public function general_settings_save(): void
    {
        Livewire::actingAs($this->member)
            ->test(ProjectSettings::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->set('name', 'Website Rebuild')
            ->set('description', 'Ship the new marketing site.')
            ->call('saveGeneral')
            ->assertHasNoErrors();

        $this->assertSame('Website Rebuild', $this->project->fresh()->name);
    }

    #[Test]
    public function switching_health_to_manual_and_back_latches_and_releases(): void
    {
        $component = Livewire::actingAs($this->member)
            ->test(ProjectSettings::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->set('healthMode', 'manual')
            ->set('health', 'at_risk')
            ->call('saveGeneral')
            ->assertHasNoErrors();

        $this->assertTrue((bool) $this->project->fresh()->health_set_manually);

        $component->set('healthMode', 'auto')->call('saveGeneral')->assertHasNoErrors();

        $this->assertFalse((bool) $this->project->fresh()->health_set_manually);
    }

    #[Test]
    public function board_columns_can_be_added_reordered_and_removed(): void
    {
        $component = Livewire::actingAs($this->member)
            ->test(ProjectSettings::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->set('statusName', 'In review')
            ->set('statusCategory', 'review')
            ->set('statusColor', 'purple')
            ->call('addStatus')
            ->assertHasNoErrors();

        $added = TaskStatus::query()
            ->where('project_id', $this->project->getKey())
            ->where('name', 'In review')
            ->firstOrFail();

        $ids = TaskStatus::query()
            ->where('project_id', $this->project->getKey())
            ->pluck('id')
            ->reverse()
            ->values()
            ->all();

        $component->call('reorderStatuses', $ids);

        $this->assertSame(0, (int) TaskStatus::query()->findOrFail($ids[0])->position);

        $component->call('deleteStatus', $added->getKey());

        $this->assertDatabaseMissing('task_statuses', ['id' => $added->getKey()]);
    }

    #[Test]
    public function a_column_holding_tasks_is_not_deleted(): void
    {
        $status = TaskStatus::query()
            ->where('project_id', $this->project->getKey())
            ->where('is_default', true)
            ->firstOrFail();

        Livewire::actingAs($this->member)
            ->test(ProjectSettings::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->call('deleteStatus', $status->getKey());

        $this->assertDatabaseHas('task_statuses', ['id' => $status->getKey()]);
    }

    #[Test]
    public function members_can_be_added_re_roled_and_removed(): void
    {
        $colleague = $this->makeMember($this->workspace);

        $component = Livewire::actingAs($this->member)
            ->test(ProjectSettings::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->set('addMemberId', $colleague->getKey())
            ->call('addMember')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $this->project->getKey(),
            'user_id' => $colleague->getKey(),
            'role' => ProjectRole::Member->value,
        ]);

        $component->call('changeMemberRole', $colleague->getKey(), ProjectRole::Manager->value);

        $this->assertDatabaseHas('project_members', [
            'user_id' => $colleague->getKey(),
            'role' => ProjectRole::Manager->value,
        ]);

        $component->call('removeMember', $colleague->getKey());

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $this->project->getKey(),
            'user_id' => $colleague->getKey(),
        ]);
    }

    #[Test]
    public function the_project_owner_cannot_be_removed(): void
    {
        Livewire::actingAs($this->member)
            ->test(ProjectSettings::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->call('removeMember', $this->member->getKey());

        $this->assertDatabaseHas('project_members', [
            'project_id' => $this->project->getKey(),
            'user_id' => $this->member->getKey(),
        ]);
    }

    #[Test]
    public function somebody_can_be_invited_to_the_project(): void
    {
        Livewire::actingAs($this->member)
            ->test(ProjectSettings::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->set('inviteEmail', 'new.person@example.com')
            ->set('inviteRole', ProjectRole::Member->value)
            ->call('invite')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('invitations', [
            'email' => 'new.person@example.com',
            'project_id' => $this->project->getKey(),
        ]);
    }

    #[Test]
    public function tags_and_custom_fields_can_be_managed(): void
    {
        $component = Livewire::actingAs($this->member)
            ->test(ProjectSettings::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->set('tagName', 'Needs legal')
            ->set('tagColor', 'amber')
            ->call('addTag')
            ->assertHasNoErrors();

        $tag = Tag::query()->where('name', 'Needs legal')->firstOrFail();

        $component->call('editTag', $tag->getKey())
            ->set('tagName', 'Legal review')
            ->call('saveTag')
            ->assertHasNoErrors();

        $this->assertSame('Legal review', $tag->fresh()->name);

        $component->set('fieldName', 'Environment')
            ->set('fieldType', CustomFieldType::Select->value)
            ->set('fieldOptions', "Staging\nProduction")
            ->call('addField')
            ->assertHasNoErrors();

        $field = CustomField::query()->where('name', 'Environment')->firstOrFail();

        $this->assertSame(['Staging', 'Production'], $field->options);

        $component->call('deleteField', $field->getKey());

        $this->assertDatabaseMissing('custom_fields', ['id' => $field->getKey()]);
    }

    #[Test]
    public function archiving_and_restoring_work(): void
    {
        $component = Livewire::actingAs($this->member)
            ->test(ProjectSettings::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->call('archive');

        $this->assertTrue((bool) $this->project->fresh()->is_archived);

        $component->call('restore');

        $this->assertFalse((bool) $this->project->fresh()->is_archived);
    }

    #[Test]
    public function deleting_demands_the_project_key_typed_out(): void
    {
        $component = Livewire::actingAs($this->member)
            ->test(ProjectSettings::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->set('deleteConfirmation', 'nope')
            ->call('destroy')
            ->assertHasErrors('deleteConfirmation');

        $this->assertNotSoftDeleted($this->project);

        $component->set('deleteConfirmation', 'web')
            ->call('destroy')
            ->assertRedirect(route('app.projects.index', $this->workspace));

        $this->assertSoftDeleted($this->project);
    }

    /* ---------------------------------------------------------------- *
     * Authorisation
     * ---------------------------------------------------------------- */

    #[Test]
    public function an_unknown_settings_section_falls_back_to_general(): void
    {
        Livewire::actingAs($this->member)
            ->withQueryParams(['section' => 'wat'])
            ->test(ProjectSettings::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->assertSet('section', 'general')
            ->assertSee('Identity');
    }

    #[Test]
    public function clearing_the_manager_and_the_stage_is_accepted(): void
    {
        Livewire::actingAs($this->member)
            ->test(ProjectSettings::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->set('managerId', '')
            ->set('statusId', '')
            ->call('saveGeneral')
            ->assertHasNoErrors();
    }

    #[Test]
    public function a_plain_member_cannot_open_project_settings(): void
    {
        $plain = $this->makeMember($this->workspace);

        $this->actingAs($plain)
            ->get(route('app.projects.settings', [$this->workspace, $this->project]))
            ->assertForbidden();
    }

    #[Test]
    public function the_overview_survives_a_health_assessment_with_reasons(): void
    {
        $status = TaskStatus::query()
            ->where('project_id', $this->project->getKey())
            ->where('is_default', true)
            ->firstOrFail();

        for ($i = 0; $i < 6; $i++) {
            Task::factory()->for($this->project)->create([
                'workspace_id' => $this->project->workspace_id,
                'status_id' => $status->getKey(),
                'assignee_id' => $this->member->getKey(),
                'due_date' => now()->subDays(10),
                'completed_at' => null,
            ]);
        }

        Milestone::query()->create([
            'workspace_id' => $this->project->workspace_id,
            'project_id' => $this->project->getKey(),
            'name' => 'Slipped',
            'status' => MilestoneStatus::Delayed,
            'due_date' => now()->subWeek(),
            'position' => 0,
            'progress' => 20,
        ]);

        $this->project->forceFill(['target_date' => now()->subDays(3)])->save();

        Livewire::actingAs($this->member)
            ->test(ProjectShow::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->assertOk()
            ->assertSee('Overdue tasks')
            ->assertSee('Delayed milestones');
    }

    /* ---------------------------------------------------------------- *
     * Helpers
     * ---------------------------------------------------------------- */

    private function fillProject(): void
    {
        Milestone::query()->create([
            'workspace_id' => $this->project->workspace_id,
            'project_id' => $this->project->getKey(),
            'name' => 'Launch',
            'status' => MilestoneStatus::InProgress,
            'due_date' => now()->addWeeks(3),
            'owner_id' => $this->member->getKey(),
            'position' => 0,
            'progress' => 40,
        ]);

        Attachment::query()->create([
            'workspace_id' => $this->project->workspace_id,
            'attachable_id' => $this->project->getKey(),
            'attachable_type' => $this->project->getMorphClass(),
            'uploaded_by' => $this->member->getKey(),
            'disk' => 'private',
            'path' => 'attachments/brief.pdf',
            'original_name' => 'brief.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 20480,
        ]);

        $this->project->forceFill([
            'description' => 'Rebuild the marketing site.',
            'budget' => '10000.00',
            'currency' => 'USD',
            'target_date' => now()->addMonth(),
            'health_note' => 'Client sign-off slipped a week; the schedule absorbs it.',
            'manager_id' => $this->member->getKey(),
            'client_name' => 'Northwind',
        ])->save();
    }
}
