<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A test that reaches the network is a bug: AI provider calls must be faked
        // explicitly by the test that needs them. This makes an unfaked call fail loudly
        // instead of hanging or, worse, succeeding against a real endpoint.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        // The workspace binding is a container singleton; leaking it between tests would
        // make isolation assertions pass for the wrong reason.
        if ($this->app !== null) {
            $this->app->make(CurrentWorkspace::class)->forget();
        }

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ *
     * World building
     *
     * Every tenant-scoped test needs the same shape: a workspace, a member in
     * it with a known role, and something to act on. These helpers keep that
     * setup to one line so the test body is only the thing under test.
     * ------------------------------------------------------------------ */

    /**
     * Create a workspace whose owner is a fresh user.
     */
    protected function makeWorkspace(array $attributes = []): Workspace
    {
        return Workspace::factory()->create($attributes);
    }

    /**
     * Create a user and attach them to the workspace with the given role.
     */
    protected function makeMember(
        Workspace $workspace,
        WorkspaceRole $role = WorkspaceRole::Member,
        array $attributes = [],
    ): User {
        $user = User::factory()->create($attributes);

        $workspace->members()->attach($user->id, [
            'role' => $role->value,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    /**
     * Create a project inside a workspace, optionally adding members with project roles.
     *
     * @param array<int, array{0: User, 1: ProjectRole}> $members
     */
    protected function makeProject(Workspace $workspace, array $members = [], array $attributes = []): Project
    {
        $project = Project::factory()
            ->for($workspace)
            ->create($attributes);

        foreach ($members as [$user, $role]) {
            $project->members()->attach($user->id, [
                'role' => $role->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $project->fresh();
    }

    protected function makeTask(Project $project, array $attributes = []): Task
    {
        return Task::factory()
            ->for($project)
            ->create(['workspace_id' => $project->workspace_id] + $attributes);
    }

    /**
     * Bind a workspace for the duration of the closure, the way the request
     * middleware and AI runs do.
     */
    protected function inWorkspace(Workspace $workspace, callable $callback): mixed
    {
        return $this->app->make(CurrentWorkspace::class)->runFor($workspace, $callback);
    }

    /**
     * Act as a user with a workspace bound — the normal authenticated state.
     */
    protected function actingInWorkspace(User $user, Workspace $workspace): static
    {
        $this->actingAs($user);
        $this->app->make(CurrentWorkspace::class)->set($workspace);

        return $this;
    }

    /* ------------------------------------------------------------------ *
     * Isolation assertions
     *
     * Named so a failure reads as what it is: a tenancy breach, not a
     * generic assertion failure.
     * ------------------------------------------------------------------ */

    /**
     * Assert the given user cannot reach the URL at all — 403 or 404, never 200.
     *
     * 404 is an acceptable and often preferable outcome: it does not confirm that
     * the record exists.
     */
    protected function assertDeniedAccess(User $user, string $url, string $method = 'get'): void
    {
        $response = $this->actingAs($user)->{$method}($url);

        $this->assertContains(
            $response->getStatusCode(),
            [403, 404],
            "Expected {$user->email} to be denied {$method} {$url}, "
            ."but got {$response->getStatusCode()}. This is a tenancy or authorization breach.",
        );
    }

    /**
     * Assert a query scoped to one workspace cannot see a record from another.
     */
    protected function assertNotVisibleAcrossWorkspaces(Workspace $viewer, string $modelClass, int $foreignId): void
    {
        $found = $this->inWorkspace($viewer, fn () => $modelClass::query()->find($foreignId));

        $this->assertNull(
            $found,
            "{$modelClass} #{$foreignId} leaked into workspace {$viewer->slug}. "
            .'The workspace scope is not being applied.',
        );
    }
}
