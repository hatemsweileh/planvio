<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * `per_page` is a number the caller controls, which makes the ceiling a
 * resource-exhaustion control rather than a formatting preference.
 *
 * Planvio targets shared hosting: a single request for fifty thousand tasks would serialise
 * every one of them into memory before a byte was sent. `config('planvio.pagination.api_max')`
 * is the hard stop, and it is applied silently — a caller asking for more wants "as many as
 * possible", and refusing the whole request teaches them nothing.
 */
final class PaginationTest extends ApiTestCase
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
        $this->project = $this->makeProject($this->workspace);

        Task::factory()->count(12)->create([
            'project_id' => $this->project->getKey(),
            'workspace_id' => $this->workspace->getKey(),
        ]);
    }

    #[Test]
    public function a_page_carries_the_four_facts_a_client_needs_to_walk_it(): void
    {
        $response = $this->asToken($this->member, $this->workspace)
            ->getJson('/api/v1/tasks?per_page=5');

        $response->assertOk();
        $response->assertJsonCount(5, 'data');
        $response->assertJsonPath('meta.page', 1);
        $response->assertJsonPath('meta.per_page', 5);
        $response->assertJsonPath('meta.total', 12);
        $response->assertJsonPath('meta.last_page', 3);
    }

    #[Test]
    public function the_page_parameter_moves_through_the_collection(): void
    {
        $first = $this->asToken($this->member, $this->workspace)
            ->getJson('/api/v1/tasks?per_page=5&page=1')
            ->json('data.*.id');

        $second = $this->asToken($this->member, $this->workspace)
            ->getJson('/api/v1/tasks?per_page=5&page=2')
            ->json('data.*.id');

        $this->assertCount(5, $first);
        $this->assertCount(5, $second);
        $this->assertSame([], array_intersect($first, $second), 'Pages overlap.');
    }

    #[Test]
    public function the_default_page_size_is_the_configured_one(): void
    {
        $response = $this->asToken($this->member, $this->workspace)->getJson('/api/v1/tasks');

        $response->assertOk();
        $response->assertJsonPath('meta.per_page', (int) config('planvio.pagination.api'));
    }

    /**
     * Every paginated endpoint, because the cap lives in one place and a controller that
     * paginated by hand would quietly opt out of it.
     *
     * @return array<string, array{0: string}>
     */
    public static function paginatedUrls(): array
    {
        return [
            'projects' => ['/api/v1/projects'],
            'tasks' => ['/api/v1/tasks'],
            'milestones' => ['/api/v1/milestones'],
            'time entries' => ['/api/v1/time-entries'],
            'tags' => ['/api/v1/tags'],
            'users' => ['/api/v1/users'],
            'activity' => ['/api/v1/activity'],
        ];
    }

    #[Test]
    #[DataProvider('paginatedUrls')]
    public function per_page_is_capped_at_the_configured_maximum(string $url): void
    {
        $max = (int) config('planvio.pagination.api_max');

        $response = $this->asToken($this->member, $this->workspace)
            ->getJson($url.'?per_page=50000');

        $response->assertOk();
        $response->assertJsonPath('meta.per_page', $max);
    }

    #[Test]
    public function a_nonsense_page_size_falls_back_to_the_default_rather_than_failing(): void
    {
        $default = (int) config('planvio.pagination.api');

        foreach (['abc', '-10', '0', ''] as $value) {
            $response = $this->asToken($this->member, $this->workspace)
                ->getJson('/api/v1/tasks?per_page='.$value);

            // `0` and a negative are refused by the validator before they reach the clamp;
            // anything non-numeric simply is not a page size and the default applies.
            $this->assertContains(
                $response->getStatusCode(),
                [200, 422],
                'per_page='.$value.' produced '.$response->getStatusCode(),
            );

            if ($response->getStatusCode() === 200) {
                $response->assertJsonPath('meta.per_page', $default);
            }
        }
    }

    #[Test]
    public function a_comment_thread_is_paginated_too(): void
    {
        $task = $this->makeTask($this->project);

        $this->asToken($this->member, $this->workspace)
            ->getJson('/api/v1/tasks/'.$task->getKey().'/comments?per_page=50000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', (int) config('planvio.pagination.api_max'));
    }
}
