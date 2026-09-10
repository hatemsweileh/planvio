<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Project;
use App\Models\RecentItem;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TrackRecentItem, exercised through routes registered for the test: the product surface
 * that will carry it in earnest does not exist yet, and the middleware's whole job only
 * happens when a real route parameter resolves to a real model.
 */
final class TrackRecentItemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'workspace', 'recent'])
            ->get('/probe/project/{project}', fn (Project $project): string => 'project');

        Route::middleware(['web', 'auth', 'workspace', 'recent'])
            ->get('/probe/project/{project}/task/{task}', fn (Project $project, Task $task): string => 'task');

        Route::middleware(['web', 'auth', 'workspace', 'recent'])
            ->post('/probe/project/{project}', fn (Project $project): string => 'posted');
    }

    #[Test]
    public function opening_a_project_records_it(): void
    {
        $workspace = $this->makeWorkspace();
        $member = $this->makeMember($workspace);
        $project = $this->makeProject($workspace);

        $this->actingAs($member)->get('/probe/project/'.$project->getKey())->assertOk();

        $this->assertDatabaseHas('recent_items', [
            'user_id' => $member->getKey(),
            'workspace_id' => $workspace->getKey(),
            'viewable_type' => $project->getMorphClass(),
            'viewable_id' => $project->getKey(),
        ]);
    }

    #[Test]
    public function only_the_most_specific_subject_is_recorded(): void
    {
        $workspace = $this->makeWorkspace();
        $member = $this->makeMember($workspace);
        $project = $this->makeProject($workspace);
        $task = $this->makeTask($project);

        $this->actingAs($member)
            ->get('/probe/project/'.$project->getKey().'/task/'.$task->getKey())
            ->assertOk();

        // A recents list where every task drags its project along stops being a list of
        // what you were doing.
        $this->assertDatabaseHas('recent_items', ['viewable_type' => $task->getMorphClass()]);
        $this->assertDatabaseMissing('recent_items', ['viewable_type' => $project->getMorphClass()]);
    }

    #[Test]
    public function reopening_a_record_moves_it_rather_than_duplicating_it(): void
    {
        $workspace = $this->makeWorkspace();
        $member = $this->makeMember($workspace);
        $project = $this->makeProject($workspace);

        $this->actingAs($member)->get('/probe/project/'.$project->getKey());

        $first = RecentItem::withoutWorkspaceScope()->sole();

        $this->travel(5)->minutes();

        $this->actingAs($member)->get('/probe/project/'.$project->getKey());

        $this->assertSame(1, RecentItem::withoutWorkspaceScope()->count());
        $this->assertTrue(
            $first->viewed_at->lessThan(RecentItem::withoutWorkspaceScope()->sole()->viewed_at),
        );
    }

    #[Test]
    public function a_write_is_not_a_view(): void
    {
        $workspace = $this->makeWorkspace();
        $member = $this->makeMember($workspace);
        $project = $this->makeProject($workspace);

        $this->actingAs($member)->post('/probe/project/'.$project->getKey())->assertOk();

        $this->assertSame(0, RecentItem::withoutWorkspaceScope()->count());
    }
}
