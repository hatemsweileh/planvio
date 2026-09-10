<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\WorkspaceRole;
use App\Http\Middleware\ApiWorkspace;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The front door: who gets in, and what they have to say to get in.
 *
 * Three separate facts are asserted here and each has failed in somebody's API before:
 * that no endpoint answers without a token, that a revoked token stops working immediately,
 * and that a scoped endpoint refuses to guess which workspace it is operating on.
 */
final class AuthenticationTest extends ApiTestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Owner);
    }

    /**
     * Every route in the table, unauthenticated. A single endpoint that answers is a hole,
     * so the list is asserted rather than a representative sample.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'me' => ['get', '/api/v1/me'],
            'workspaces' => ['get', '/api/v1/workspaces'],
            'workspace' => ['get', '/api/v1/workspaces/acme'],
            'projects' => ['get', '/api/v1/projects'],
            'create project' => ['post', '/api/v1/projects'],
            'tasks' => ['get', '/api/v1/tasks'],
            'create task' => ['post', '/api/v1/tasks'],
            'task' => ['get', '/api/v1/tasks/1'],
            'assign task' => ['post', '/api/v1/tasks/1/assign'],
            'task status' => ['post', '/api/v1/tasks/1/status'],
            'task comments' => ['get', '/api/v1/tasks/1/comments'],
            'milestones' => ['get', '/api/v1/milestones'],
            'time entries' => ['get', '/api/v1/time-entries'],
            'tags' => ['get', '/api/v1/tags'],
            'users' => ['get', '/api/v1/users'],
            'activity' => ['get', '/api/v1/activity'],
            'search' => ['get', '/api/v1/search?q=test'],
            'start ai run' => ['post', '/api/v1/ai/runs'],
        ];
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function it_refuses_every_endpoint_without_a_token(string $method, string $url): void
    {
        $response = $this->withHeaders(['Accept' => 'application/json'])->{$method}($url);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'unauthenticated');
        $response->assertJsonPath('error.status', 401);
    }

    #[Test]
    public function it_accepts_a_personal_access_token(): void
    {
        $response = $this->asToken($this->member)->getJson('/api/v1/me');

        $response->assertOk();
        $response->assertJsonPath('data.user.id', $this->member->getKey());
        $response->assertJsonPath('data.token.name', 'test-token');
    }

    #[Test]
    public function it_refuses_a_garbage_token(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer 1|not-a-real-token',
            'Accept' => 'application/json',
        ])->getJson('/api/v1/me');

        $response->assertStatus(401);
    }

    #[Test]
    public function a_revoked_token_stops_working(): void
    {
        $plain = $this->member->createToken('ci')->plainTextToken;

        $headers = ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];

        $this->withHeaders($headers)->getJson('/api/v1/me')->assertOk();

        $this->member->tokens()->delete();
        $this->forgetAuthentication();

        $this->withHeaders($headers)->getJson('/api/v1/me')->assertStatus(401);
    }

    #[Test]
    public function a_deactivated_account_cannot_use_its_token(): void
    {
        $response = $this->asToken($this->member)->getJson('/api/v1/me');
        $response->assertOk();

        $this->member->forceFill(['is_active' => false])->save();
        $this->forgetAuthentication();

        $this->asToken($this->member->fresh())
            ->getJson('/api/v1/me')
            ->assertStatus(403);
    }

    #[Test]
    public function a_scoped_endpoint_refuses_to_guess_the_workspace(): void
    {
        $response = $this->asToken($this->member)->getJson('/api/v1/tasks');

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'bad_request');
        $this->assertStringContainsString(ApiWorkspace::HEADER, (string) $response->json('error.message'));
    }

    #[Test]
    public function an_unknown_workspace_header_is_a_404(): void
    {
        $this->asToken($this->member)
            ->withHeaders([ApiWorkspace::HEADER => 'no-such-workspace'])
            ->getJson('/api/v1/tasks')
            ->assertStatus(404);
    }

    #[Test]
    public function the_workspace_header_accepts_an_id_as_well_as_a_slug(): void
    {
        $this->asToken($this->member)
            ->withHeaders([ApiWorkspace::HEADER => (string) $this->workspace->getKey()])
            ->getJson('/api/v1/tasks')
            ->assertOk();
    }

    #[Test]
    public function unscoped_endpoints_answer_without_the_header(): void
    {
        $response = $this->asToken($this->member)->getJson('/api/v1/workspaces');

        $response->assertOk();
        $response->assertJsonPath('data.0.slug', 'acme');
        $response->assertJsonPath('data.0.role', WorkspaceRole::Owner->value);
    }

    #[Test]
    public function every_response_reports_the_remaining_budget(): void
    {
        $response = $this->asToken($this->member)->getJson('/api/v1/me');

        $response->assertOk();
        $response->assertHeader('X-RateLimit-Limit');
        $response->assertHeader('X-RateLimit-Remaining');
    }

    #[Test]
    public function going_over_the_budget_is_a_429_with_a_retry_after(): void
    {
        // The real ceiling is 120 a minute, which is a lot of HTTP calls to make in a test.
        // Re-registering the named limiter is the same code path with a smaller number.
        RateLimiter::for('planvio-api', static fn (): Limit => Limit::perMinute(2)->by('fixed-test-key'));

        $this->asToken($this->member)->getJson('/api/v1/me')->assertOk();
        $this->asToken($this->member)->getJson('/api/v1/me')->assertOk();

        $refused = $this->asToken($this->member)->getJson('/api/v1/me');

        $refused->assertStatus(429);
        $refused->assertJsonPath('error.code', 'rate_limited');
        $refused->assertHeader('Retry-After');
    }

    #[Test]
    public function a_suspended_workspace_is_refused(): void
    {
        $this->workspace->forceFill(['is_suspended' => true])->save();

        $this->asToken($this->member, $this->workspace)
            ->getJson('/api/v1/tasks')
            ->assertStatus(403);
    }
}
