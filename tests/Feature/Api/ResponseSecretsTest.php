<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Nothing that is a credential may appear in a response body. Anywhere. Ever.
 *
 * The API resources are allow-lists — every field is written out by hand in
 * `App\Http\Resources` — which is what makes this assertion possible to state and cheap to
 * keep true. This suite is the proof, and it is deliberately written as a *string search over
 * the whole body* rather than as a check on the fields the resource declares: a check on
 * declared fields would pass even if a secret arrived through a nested relation, an error
 * message, a validation echo or an activity `properties` blob.
 *
 * The values hunted for are the ones that would matter most:
 *
 *   - `users.password` — the bcrypt hash. Enough to attack offline.
 *   - `users.two_factor_secret` — the TOTP seed, in both its plaintext and stored forms.
 *     Holding it means being able to generate the second factor forever.
 *   - `users.remember_token` — a session-equivalent bearer value.
 *   - `webhooks.secret` — the HMAC signing key, which is what lets somebody forge a delivery
 *     Planvio would appear to have signed.
 *   - the API token itself, whose hash sits in `personal_access_tokens`.
 */
final class ResponseSecretsTest extends ApiTestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    private Project $project;

    private Task $task;

    private Milestone $milestone;

    private Comment $comment;

    private TimeEntry $entry;

    private Tag $tag;

    private Webhook $webhook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);

        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Owner, [
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);

        $this->project = $this->makeProject($this->workspace);
        $this->task = $this->makeTask($this->project, ['assignee_id' => $this->member->getKey()]);

        $this->milestone = Milestone::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
        ]);

        $this->comment = Comment::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'commentable_type' => $this->task->getMorphClass(),
            'commentable_id' => $this->task->getKey(),
            'user_id' => $this->member->getKey(),
        ]);

        $this->entry = TimeEntry::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'task_id' => $this->task->getKey(),
            'user_id' => $this->member->getKey(),
        ]);

        $this->tag = Tag::factory()->create(['workspace_id' => $this->workspace->getKey()]);

        $this->webhook = Webhook::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'secret' => 'whsec_the_signing_key_nobody_may_read',
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function readableUrls(): array
    {
        return [
            'me' => ['/api/v1/me'],
            'workspaces' => ['/api/v1/workspaces'],
            'workspace' => ['/api/v1/workspaces/acme'],
            'projects' => ['/api/v1/projects'],
            'project' => ['/api/v1/projects/{project}'],
            'tasks' => ['/api/v1/tasks'],
            'task' => ['/api/v1/tasks/{task}'],
            'thread' => ['/api/v1/tasks/{task}/comments'],
            'comment' => ['/api/v1/comments/{comment}'],
            'milestones' => ['/api/v1/milestones'],
            'milestone' => ['/api/v1/milestones/{milestone}'],
            'time entries' => ['/api/v1/time-entries'],
            'time entry' => ['/api/v1/time-entries/{entry}'],
            'tags' => ['/api/v1/tags'],
            'tag' => ['/api/v1/tags/{tag}'],
            'users' => ['/api/v1/users'],
            'user' => ['/api/v1/users/{user}'],
            'activity' => ['/api/v1/activity'],
            'search' => ['/api/v1/search?q=a'],
        ];
    }

    #[Test]
    #[DataProvider('readableUrls')]
    public function no_response_body_carries_a_credential(string $template): void
    {
        $url = strtr($template, [
            '{project}' => (string) $this->project->getKey(),
            '{task}' => (string) $this->task->getKey(),
            '{comment}' => (string) $this->comment->getKey(),
            '{milestone}' => (string) $this->milestone->getKey(),
            '{entry}' => (string) $this->entry->getKey(),
            '{tag}' => (string) $this->tag->getKey(),
            '{user}' => (string) $this->member->getKey(),
        ]);

        $response = $this->asToken($this->member, $this->workspace)->getJson($url);

        $response->assertOk();

        $body = $response->getContent() ?: '';

        foreach ($this->secrets() as $label => $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $body,
                $label.' leaked from '.$url.'. This is a credential disclosure.',
            );
        }
    }

    #[Test]
    #[DataProvider('readableUrls')]
    public function no_response_body_names_a_credential_field(string $template): void
    {
        $url = strtr($template, [
            '{project}' => (string) $this->project->getKey(),
            '{task}' => (string) $this->task->getKey(),
            '{comment}' => (string) $this->comment->getKey(),
            '{milestone}' => (string) $this->milestone->getKey(),
            '{entry}' => (string) $this->entry->getKey(),
            '{tag}' => (string) $this->tag->getKey(),
            '{user}' => (string) $this->member->getKey(),
        ]);

        $body = $this->asToken($this->member, $this->workspace)->getJson($url)->getContent() ?: '';

        // The key names too, not only the values: a resource that sent `"password": null`
        // would pass the value check and still be one migration away from sending the hash.
        foreach ([
            '"password"',
            '"remember_token"',
            '"two_factor_secret"',
            '"two_factor_recovery_codes"',
            '"secret"',
            '"api_key"',
        ] as $field) {
            $this->assertStringNotContainsString(
                $field,
                $body,
                $field.' appears as a field in '.$url.'.',
            );
        }
    }

    #[Test]
    public function the_me_endpoint_describes_the_token_without_revealing_it(): void
    {
        $plain = $this->member->createToken('ci')->plainTextToken;

        $body = $this->withHeaders([
            'Authorization' => 'Bearer '.$plain,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/me')->getContent() ?: '';

        $hash = (string) DB::table('personal_access_tokens')->value('token');

        $this->assertStringNotContainsString($plain, $body, 'The plaintext token was echoed back.');
        $this->assertStringNotContainsString($hash, $body, 'The stored token hash was disclosed.');
        $this->assertStringContainsString('"name":"ci"', $body);
    }

    #[Test]
    public function an_error_body_carries_no_internals(): void
    {
        // A refused request is the other place a secret escapes from: an exception message
        // can carry a DSN, a path or a bound query.
        $body = $this->asToken($this->member, $this->workspace)
            ->postJson('/api/v1/tasks', ['project_id' => 999999, 'title' => 'x'])
            ->assertStatus(404)
            ->getContent() ?: '';

        foreach ($this->secrets() as $label => $secret) {
            $this->assertStringNotContainsString($secret, $body, $label.' leaked in an error body.');
        }

        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString(base_path(), $body);
    }

    /**
     * Every value that would be a disclosure, in every form it is stored in.
     *
     * @return array<string, string>
     */
    private function secrets(): array
    {
        $row = DB::table('users')->where('id', $this->member->getKey())->first();

        return array_filter([
            'The password hash' => (string) ($row->password ?? ''),
            'The remember token' => (string) ($row->remember_token ?? ''),
            // The seed as the application sees it, and the ciphertext as the column holds it.
            'The 2FA secret' => 'JBSWY3DPEHPK3PXP',
            'The stored 2FA ciphertext' => (string) ($row->two_factor_secret ?? ''),
            'The webhook signing secret' => 'whsec_the_signing_key_nobody_may_read',
        ], static fn (string $value): bool => $value !== '');
    }
}
