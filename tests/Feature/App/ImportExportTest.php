<?php

declare(strict_types=1);

namespace Tests\Feature\App;

use App\Enums\Priority;
use App\Enums\ProjectRole;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Jobs\RunTaskImport;
use App\Livewire\App\Export\Index as ExportCentre;
use App\Livewire\App\Import\Wizard;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DateResolver;
use App\Services\Import\ImportProgress;
use App\Services\Import\ImportProgressStore;
use App\Services\Import\ImportSpec;
use App\Services\Import\TaskImporter;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The CSV round trip.
 *
 * Two properties carry most of the weight here and both are about what happens when the
 * data is not what somebody hoped:
 *
 *   - **A bad row does not take the file down.** One unreadable date is reported by row
 *     number and the rest of the file still becomes tasks. The opposite behaviour — refusing
 *     the whole import — is the reason people give up on importers.
 *   - **A formula never leaves as a formula.** A task titled `=HYPERLINK(...)` is harmless
 *     text in Planvio and a running program in Excel, so the export has to neutralise it.
 *     This is a security test, not a formatting one.
 */
final class ImportExportTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    private TaskStatus $todo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');

        $this->workspace = $this->makeWorkspace(['slug' => 'acme', 'timezone' => 'UTC', 'week_starts_on' => 1]);
        $this->owner = $this->makeMember($this->workspace, WorkspaceRole::Owner, ['name' => 'Ada Lovelace']);
        $this->project = $this->makeProject($this->workspace, [], ['slug' => 'website', 'key' => 'WEB']);

        $this->todo = TaskStatus::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'name' => 'To Do',
            'color' => 'blue',
            'category' => StatusCategory::Todo,
            'position' => 1,
            'is_default' => true,
        ]);

        TaskStatus::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'name' => 'In Progress',
            'color' => 'brand',
            'category' => StatusCategory::InProgress,
            'position' => 2,
        ]);
    }

    /* ------------------------------------------------------------------ *
     * Import
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_wizard_reads_a_file_and_guesses_the_columns(): void
    {
        $component = $this->upload(
            "Title,Description,Due Date,Assigned To,Priority\n"
            ."Design the header,Two columns,2026-03-31,ada@example.test,high\n",
        );

        $component->assertSet('step', 2)
            ->assertSet('rowCount', 1)
            ->assertSet('mapping.title', 0)
            ->assertSet('mapping.description', 1)
            ->assertSet('mapping.due_date', 2)
            ->assertSet('mapping.assignee', 3)
            ->assertSet('mapping.priority', 4);
    }

    #[Test]
    public function a_semicolon_file_is_read_as_a_semicolon_file(): void
    {
        $this->upload("Title;Due Date\nShip it;2026-03-31\n")
            ->assertSet('delimiter', ';')
            ->assertSet('rowCount', 1)
            ->assertSet('mapping.title', 0);
    }

    #[Test]
    public function a_bad_date_reports_the_row_and_the_rest_of_the_file_still_imports(): void
    {
        $component = $this->upload(
            "Title,Due Date\n"
            ."Alpha,2026-03-31\n"
            ."Beta,soonish\n"
            ."Gamma,2026-04-01\n",
        );

        // Step 3 — the problem is reported by row number, with the value that broke it and
        // what Planvio will do about it. Those three together are what makes a report
        // actionable without opening the file again.
        $component->call('goTo', 3)
            ->assertSee('soonish')
            ->assertSee('Not a date Planvio can read')
            ->assertSee('Blocked');

        $report = $component->instance()->report;

        $this->assertSame(3, $report->rows);
        $this->assertSame(2, $report->importable);
        $this->assertSame(1, $report->blocked);
        // The header is row 1, so Beta — the second data line — is row 3, which is exactly
        // the number the person will see beside it in their spreadsheet.
        $this->assertSame(3, $report->errors()[0]->row);

        $component->set('acknowledged', true)->call('import');

        $progress = $component->instance()->progress;

        $this->assertNotNull($progress);
        $this->assertSame(2, $progress->created);
        $this->assertSame(1, $progress->skipped);
        $this->assertSame(0, $progress->failed);

        $titles = Task::query()->where('project_id', $this->project->getKey())->pluck('title')->all();

        $this->assertContains('Alpha', $titles);
        $this->assertContains('Gamma', $titles);
        $this->assertNotContains('Beta', $titles);

        // The failure report survives to the screen, by row number.
        $component->assertSee('Row 3');
    }

    #[Test]
    public function an_unknown_assignee_is_a_warning_that_imports_unassigned(): void
    {
        $component = $this->upload(
            "Title,Assignee\n"
            ."Alpha,nobody@example.test\n",
        );

        $component->call('goTo', 3);

        $report = $component->instance()->report;

        $this->assertSame(1, $report->importable);
        $this->assertSame(0, $report->blocked);
        $this->assertSame('Nobody in this workspace matches. Imported unassigned.', $report->warnings()[0]->message);

        $component->set('acknowledged', true)->call('import');

        $task = Task::query()->where('title', 'Alpha')->firstOrFail();

        $this->assertNull($task->assignee_id);
    }

    #[Test]
    public function an_ambiguous_date_is_a_warning_and_the_task_still_arrives(): void
    {
        $component = $this->upload("Title,Due Date\nAlpha,03/04/2026\n");

        $component->call('goTo', 3);

        $report = $component->instance()->report;

        $this->assertSame(1, $report->importable);
        $this->assertStringContainsString('will not guess', $report->warnings()[0]->message);

        $component->set('acknowledged', true)->call('import');

        $task = Task::query()->where('title', 'Alpha')->firstOrFail();

        $this->assertNull($task->due_date);
    }

    #[Test]
    public function a_row_with_no_title_is_blocked_rather_than_imported_blank(): void
    {
        $component = $this->upload("Title,Priority\n,high\nReal task,low\n");

        $component->call('goTo', 3);

        $report = $component->instance()->report;

        $this->assertSame(1, $report->blocked);
        $this->assertSame(1, $report->importable);
    }

    #[Test]
    public function imported_tasks_go_through_create_task_so_they_get_a_key_and_an_activity_entry(): void
    {
        $component = $this->upload("Title,Status,Priority\nShip the thing,In Progress,urgent\n");

        $component->call('goTo', 3)->set('acknowledged', true)->call('import');

        $task = Task::query()->where('title', 'Ship the thing')->firstOrFail();
        $task->setRelation('project', $this->project);

        $this->assertSame('WEB-'.$task->number, $task->key);
        $this->assertSame(Priority::Urgent, $task->priority);
        $this->assertSame('In Progress', TaskStatus::query()->find($task->status_id)?->name);
        $this->assertDatabaseHas('activities', [
            'subject_type' => $task->getMorphClass(),
            'subject_id' => $task->getKey(),
            'event' => 'created',
        ]);
    }

    #[Test]
    public function a_tag_the_workspace_does_not_have_is_only_created_when_asked(): void
    {
        Tag::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Design',
            'slug' => 'design',
            'color' => 'pink',
        ]);

        $component = $this->upload("Title,Tags\nAlpha,\"Design, Brand New\"\n");

        $component->call('goTo', 3);
        $this->assertStringContainsString('creating tags is switched off', $component->instance()->report->warnings()[0]->message);

        $component->set('createMissingTags', true)->set('acknowledged', true)->call('import');

        $task = Task::query()->where('title', 'Alpha')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['Design', 'Brand New'],
            $task->tags()->pluck('name')->all(),
        );
    }

    #[Test]
    public function a_cell_that_looks_like_a_formula_is_imported_as_plain_text(): void
    {
        $component = $this->upload("Title\n\"=HYPERLINK(\"\"http://evil.test\"\",\"\"Click\"\")\"\n");

        $component->call('goTo', 3)->set('acknowledged', true)->call('import');

        $this->assertDatabaseHas('tasks', [
            'project_id' => $this->project->getKey(),
            'title' => '=HYPERLINK("http://evil.test","Click")',
        ]);
    }

    #[Test]
    public function a_large_file_is_queued_rather_than_written_inside_the_request(): void
    {
        Queue::fake();

        $rows = 'Title
';

        foreach (range(1, Wizard::QUEUE_THRESHOLD + 5) as $n) {
            $rows .= 'Task '.$n.'
';
        }

        $component = $this->upload($rows)
            ->call('goTo', 3)
            ->set('acknowledged', true)
            ->call('import');

        $component->assertSet('queued', true)->assertSee('Running in the background');

        Queue::assertPushed(RunTaskImport::class);

        // Nothing was written here: the request's job was to hand the work over.
        $this->assertSame(0, Task::query()->where('project_id', $this->project->getKey())->count());
        $this->assertTrue($component->instance()->progress?->isRunning());
    }

    #[Test]
    public function the_queued_job_writes_the_rows_and_clears_the_staged_file(): void
    {
        $path = $this->stage('Title,Priority
Alpha,high
Beta,low
');
        $token = (string) Str::ulid();

        $this->runJob($path, $token);

        $this->assertSame(2, Task::query()->where('project_id', $this->project->getKey())->count());

        $progress = app(ImportProgressStore::class)->get($token);

        $this->assertSame(ImportProgress::FINISHED, $progress?->status);
        $this->assertSame(2, $progress?->created);

        // The staged upload is not left behind for nobody to find.
        Storage::disk('private')->assertMissing($path);
    }

    #[Test]
    public function the_queued_job_re_checks_the_actor_before_writing_anything(): void
    {
        $stranger = $this->makeMember($this->makeWorkspace(['slug' => 'northwind']));
        $path = $this->stage('Title
Alpha
');
        $token = (string) Str::ulid();

        $this->runJob($path, $token, $stranger);

        $this->assertSame(0, Task::query()->where('project_id', $this->project->getKey())->count());

        $progress = app(ImportProgressStore::class)->get($token);

        $this->assertSame(ImportProgress::FAILED, $progress?->status);
        $this->assertStringContainsString('no longer have permission', (string) $progress?->message);
        Storage::disk('private')->assertMissing($path);
    }

    #[Test]
    public function a_job_pointed_at_a_path_outside_the_import_directory_does_nothing(): void
    {
        $token = (string) Str::ulid();

        $spec = new ImportSpec(
            workspaceId: (int) $this->workspace->getKey(),
            projectId: (int) $this->project->getKey(),
            actorId: (int) $this->owner->getKey(),
            path: '../../../.env',
            delimiter: ',',
            mapping: ['title' => 0],
            createMissingTags: false,
            token: $token,
            total: 1,
        );

        (new RunTaskImport($spec->toArray()))->handle(
            app(CurrentWorkspace::class),
            app(ImportProgressStore::class),
            app(TaskImporter::class),
            app(DateResolver::class),
        );

        $this->assertSame(ImportProgress::FAILED, app(ImportProgressStore::class)->get($token)?->status);
        $this->assertSame(0, Task::query()->where('project_id', $this->project->getKey())->count());
    }

    #[Test]
    public function the_wizard_offers_a_template_file_with_the_columns_it_understands(): void
    {
        $response = Livewire::actingAs($this->owner)
            ->test(Wizard::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->call('template');

        $response->assertFileDownloaded('planvio-import-template.csv');
    }

    #[Test]
    public function pointing_a_second_field_at_a_column_releases_the_first(): void
    {
        $component = $this->upload('Title,Notes
Alpha,Something
');

        // The guess put Notes on the description; claiming it for the status has to let it
        // go, or the same text would be imported into two fields.
        $component->assertSet('mapping.description', 1)
            ->set('mapping.status', 1)
            ->assertSet('mapping.status', 1)
            ->assertSet('mapping.description', '');
    }

    #[Test]
    public function the_wizard_will_not_move_on_until_the_title_is_mapped(): void
    {
        $component = $this->upload('Reference,Notes
A-1,Something
');

        $component->assertSet('step', 2)
            ->assertSee('Point Title at a column before continuing')
            ->call('next')
            ->assertSet('step', 2)
            ->set('mapping.title', 0)
            ->call('next')
            ->assertSet('step', 3);
    }

    #[Test]
    public function the_project_header_offers_the_wizard_the_export_and_the_recurrences(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('app.projects.tasks', [$this->workspace, $this->project]))
            ->assertOk()
            ->getContent();

        // A feature nobody can find is a feature nobody has. These four live behind one
        // overflow control rather than in the tab bar, but they are reachable from the
        // surface people actually work on.
        foreach ([
            route('app.projects.import', [$this->workspace, $this->project]),
            route('app.projects.recurring', [$this->workspace, $this->project]),
            route('app.projects.report', [$this->workspace, $this->project]),
            route('app.export', $this->workspace),
        ] as $url) {
            $this->assertStringContainsString(e($url), (string) $html);
        }
    }

    #[Test]
    public function somebody_from_another_workspace_cannot_reach_the_wizard_or_the_export(): void
    {
        $outsider = $this->makeMember($this->makeWorkspace(['slug' => 'northwind']), WorkspaceRole::Owner);

        foreach ([
            route('app.projects.import', [$this->workspace, $this->project]),
            route('app.export', $this->workspace),
            route('app.export.download', [$this->workspace, 'tasks']),
        ] as $url) {
            $this->assertDeniedAccess($outsider, $url);
        }
    }

    #[Test]
    public function a_member_without_task_create_cannot_open_the_wizard(): void
    {
        $guest = $this->makeMember($this->workspace, WorkspaceRole::Guest);

        $this->project->members()->attach($guest->id, [
            'role' => ProjectRole::Guest->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDeniedAccess(
            $guest,
            route('app.projects.import', [$this->workspace, $this->project]),
        );
    }

    /* ------------------------------------------------------------------ *
     * Export
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_formula_cell_is_neutralised_on_export(): void
    {
        $this->task('=HYPERLINK("http://evil.test","Click")');
        $this->task('+1-555-0100');
        $this->task('Perfectly ordinary');

        $body = $this->download('tasks');

        // The apostrophe is the spreadsheet convention for "this is text": Excel strips it
        // on display and never evaluates what follows.
        $this->assertStringContainsString("'=HYPERLINK", $body);
        $this->assertStringContainsString("'+1-555-0100", $body);

        // And nothing that would still run: no cell starts with the bare formula character.
        $this->assertStringNotContainsString(',=HYPERLINK', $body);
        $this->assertStringNotContainsString('"=HYPERLINK', $body);

        $this->assertStringContainsString('Perfectly ordinary', $body);
    }

    #[Test]
    public function the_export_honours_the_filters_it_is_given(): void
    {
        $this->task('Urgent thing', ['priority' => Priority::Urgent]);
        $this->task('Quiet thing', ['priority' => Priority::Low]);

        $body = $this->download('tasks', ['priority' => ['urgent']]);

        $this->assertStringContainsString('Urgent thing', $body);
        $this->assertStringNotContainsString('Quiet thing', $body);
    }

    #[Test]
    public function the_file_starts_with_a_byte_order_mark_and_the_header_row(): void
    {
        $this->task('Anything');

        $body = $this->download('tasks');

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('Key', $body);
        $this->assertStringContainsString('Title', $body);
    }

    #[Test]
    public function projects_and_time_entries_export_too(): void
    {
        $task = $this->task('Anything');

        TimeEntry::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'task_id' => $task->getKey(),
            'user_id' => $this->owner->getKey(),
            'minutes' => 90,
            'description' => 'Wrote it down',
            'spent_on' => '2026-03-02',
            'is_billable' => true,
        ]);

        $projects = $this->download('projects');

        $this->assertStringContainsString($this->project->name, $projects);
        $this->assertStringContainsString('WEB', $projects);

        $time = $this->download('time');

        $this->assertStringContainsString('Wrote it down', $time);
        $this->assertStringContainsString('1.5', $time);
    }

    #[Test]
    public function a_guest_may_not_export_the_workspace(): void
    {
        $guest = $this->makeMember($this->workspace, WorkspaceRole::Guest);

        $this->assertDeniedAccess(
            $guest,
            route('app.export.download', [$this->workspace, 'tasks']),
        );

        $this->assertDeniedAccess($guest, route('app.export', $this->workspace));
    }

    #[Test]
    public function an_unknown_export_type_is_a_404(): void
    {
        $this->actingAs($this->owner)
            ->get(route('app.export.download', [$this->workspace, 'everything']))
            ->assertNotFound();
    }

    #[Test]
    public function the_export_screen_counts_the_rows_and_links_to_the_file(): void
    {
        $this->task('Anything');

        Livewire::actingAs($this->owner)
            ->test(ExportCentre::class, ['workspace' => $this->workspace])
            ->assertSee('Download 1 row')
            ->assertSee('Preview')
            ->set('search', 'nothing at all')
            ->assertSee('Nothing matches these filters');
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Put a CSV where the wizard would have staged it, so the queued path can be exercised
     * without going through the upload step.
     */
    private function stage(string $csv): string
    {
        $path = 'imports/'.$this->workspace->getKey().'/'.Str::ulid().'.csv';

        Storage::disk('private')->put($path, $csv);

        return $path;
    }

    private function runJob(string $path, string $token, ?User $actor = null): void
    {
        $spec = new ImportSpec(
            workspaceId: (int) $this->workspace->getKey(),
            projectId: (int) $this->project->getKey(),
            actorId: (int) ($actor ?? $this->owner)->getKey(),
            path: $path,
            delimiter: ',',
            mapping: ['title' => 0, 'priority' => 1],
            createMissingTags: false,
            token: $token,
            total: 2,
        );

        (new RunTaskImport($spec->toArray()))->handle(
            app(CurrentWorkspace::class),
            app(ImportProgressStore::class),
            app(TaskImporter::class),
            app(DateResolver::class),
        );
    }

    private function upload(string $csv, string $name = 'tasks.csv'): Testable
    {
        return Livewire::actingAs($this->owner)
            ->test(Wizard::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->set('file', UploadedFile::fake()->createWithContent($name, $csv));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function download(string $type, array $query = []): string
    {
        $response = $this->actingAs($this->owner)
            ->get(route('app.export.download', [$this->workspace, $type, ...$query]));

        $response->assertOk();

        return $response->streamedContent();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function task(string $title, array $attributes = []): Task
    {
        return $this->makeTask($this->project, [
            'title' => $title,
            'status_id' => $this->todo->getKey(),
            'reporter_id' => $this->owner->getKey(),
            'created_by' => $this->owner->getKey(),
        ] + $attributes);
    }
}
