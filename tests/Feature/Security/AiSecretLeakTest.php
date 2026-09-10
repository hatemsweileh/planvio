<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Ai\Agent\AgentRunner;
use App\Ai\Contracts\AiChatMessage;
use App\Ai\Contracts\AiChatRequest;
use App\Ai\Providers\AiProviderException;
use App\Ai\Providers\ProviderFactory;
use App\Enums\AiMode;
use App\Enums\AiRunStatus;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\AiMessage;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\AiToolRun;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * CLAUDE.md rule 4 and AI_SECURITY.md, "What is logged, and what is never logged":
 *
 * > Never recorded, anywhere: API keys, in any form, including in error text.
 *
 * "In any form" is the part worth attacking. A key does not usually leak because somebody
 * wrote `Log::info($key)`; it leaks because a 401 body echoed it, or because a chained
 * exception carried the frame arguments that built the request, or because a model was
 * serialised into a response.
 *
 * So this suite configures a provider with a canary key, drives the AI layer through every
 * failure mode it has, and then reads back everything that failure could have been written
 * into: the log file on disk, `ai_runs`, `ai_tool_runs`, `ai_messages`, `activities`,
 * `notifications`, `audit_logs`, the exception itself and its stack trace, and the model's
 * own serialisation.
 */
final class AiSecretLeakTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Shaped like the credentials this product actually meets, so the shape-based half of the
     * redactor is exercised rather than bypassed.
     */
    private const CANARY_KEY = 'sk-planvio-canary-0000111122223333';

    /** An administrator-configured header value: no vendor prefix, no useful entropy. */
    private const CANARY_HEADER = 'planvio-canary-header-value';

    private string $logPath;

    private Workspace $workspace;

    private User $actor;

    private Project $project;

    private AiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.enabled' => true]);

        Notification::fake();

        $this->logPath = storage_path('logs/ai-secret-leak-'.bin2hex(random_bytes(6)).'.log');

        config([
            'logging.default' => 'canary',
            'logging.channels.canary' => [
                'driver' => 'single',
                'path' => $this->logPath,
                'level' => 'debug',
            ],
        ]);

        Log::forgetChannel('canary');
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            @unlink($this->logPath);
        }

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ *
     * The provider rejects the credential and echoes it back
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_401_that_echoes_the_key_leaves_it_nowhere_at_all(): void
    {
        $run = $this->stage();

        // The single most likely place for a credential to escape: an endpoint quoting the
        // key it just refused, in both the message and a nested field.
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => [
                'message' => 'Incorrect API key provided: '.self::CANARY_KEY.'. You can find your API key at https://example.test/keys.',
                'type' => 'invalid_request_error',
                'param' => null,
                'code' => 'invalid_api_key',
                'api_key' => self::CANARY_KEY,
            ],
        ], 401)]);

        $finished = $this->drive($run, 'Create a task.');

        $this->assertSame(AiRunStatus::Failed, $finished->status);
        $this->assertNotNull($finished->error, 'The run recorded no reason at all, so this proves nothing.');

        $this->assertNothingLeaked('a 401 echoing the credential');
    }

    #[Test]
    public function the_error_the_user_is_shown_names_the_driver_and_nothing_else(): void
    {
        $run = $this->stage();

        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['message' => 'Bad key '.self::CANARY_KEY],
        ], 401)]);

        $finished = $this->drive($run, 'Create a task.');

        $error = (string) $finished->error;
        $summary = (string) $finished->summary;

        $this->assertStringNotContainsString(self::CANARY_KEY, $error);
        $this->assertStringNotContainsString(self::CANARY_KEY, $summary);

        // Nor the endpoint, which can itself carry credentials in its userinfo.
        $this->assertStringNotContainsString('api.openai.com', $error);
        $this->assertStringNotContainsString('https://', $error);
    }

    /* ------------------------------------------------------------------ *
     * The transport blows up with the key in the message
     * ------------------------------------------------------------------ */

    #[Test]
    public function an_exception_carrying_the_key_is_dropped_rather_than_chained(): void
    {
        $run = $this->stage();

        Http::fake(['api.openai.com/*' => static function (): never {
            throw new RuntimeException('cURL error 60 while sending Authorization: Bearer '.self::CANARY_KEY);
        }]);

        $finished = $this->drive($run, 'Create a task.');

        $this->assertSame(AiRunStatus::Failed, $finished->status);
        $this->assertNothingLeaked('a transport exception quoting the credential');
    }

    #[Test]
    public function the_provider_exception_itself_carries_no_credential_in_message_context_or_trace(): void
    {
        $this->stage();

        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['message' => 'Incorrect API key provided: '.self::CANARY_KEY],
        ], 401)]);

        $provider = app(ProviderFactory::class)->make($this->provider);

        try {
            $provider->chat(new AiChatRequest(
                messages: [AiChatMessage::user('hello')],
                model: 'gpt-4o-mini',
                systemPrompt: 'Be brief.',
            ));

            $this->fail('The 401 did not produce an exception.');
        } catch (AiProviderException $e) {
            $this->assertStringNotContainsString(self::CANARY_KEY, $e->getMessage());
            $this->assertStringNotContainsString(
                self::CANARY_KEY,
                json_encode($e->context(), JSON_THROW_ON_ERROR),
                'The exception context carried the credential the endpoint echoed.',
            );

            // A chained previous exception would drag its own frame arguments into any log
            // that renders it, which is why nothing is chained here.
            $this->assertNull($e->getPrevious(), 'A previous exception was chained.');
            $this->assertStringNotContainsString(self::CANARY_KEY, $e->getTraceAsString());
        }
    }

    #[Test]
    public function the_admin_connection_test_reports_a_failure_without_the_credential(): void
    {
        $this->stage();

        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['message' => 'Incorrect API key provided: '.self::CANARY_KEY],
        ], 401)]);

        $health = app(ProviderFactory::class)->make($this->provider)->testConnection();

        $this->assertFalse($health->ok);
        $this->assertStringNotContainsString(self::CANARY_KEY, json_encode($health, JSON_THROW_ON_ERROR));

        $this->assertNothingLeaked('the administration connection test');
    }

    /* ------------------------------------------------------------------ *
     * The record itself
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_provider_record_never_serialises_its_key(): void
    {
        $this->stage();

        $fresh = AiProvider::query()->findOrFail($this->provider->getKey());

        $this->assertArrayNotHasKey('api_key', $fresh->toArray());
        $this->assertStringNotContainsString(self::CANARY_KEY, json_encode($fresh, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::CANARY_KEY, (string) json_encode($fresh->toArray()));

        // Encrypted at rest: the column itself does not hold the plaintext either.
        $stored = (string) DB::table('ai_providers')->where('id', $fresh->getKey())->value('api_key');

        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString(self::CANARY_KEY, $stored);

        // But it is still the key: containment must not be "the value was lost".
        $this->assertSame(self::CANARY_KEY, $fresh->api_key);
    }

    #[Test]
    public function a_tool_argument_somebody_pasted_a_key_into_is_redacted_before_it_is_stored(): void
    {
        $run = $this->stage();

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->toolCall('create_task', [
                'project_id' => (int) $this->project->getKey(),
                // The credential is in the title as well as the description, so it travels
                // into the tool's own success summary and from there into the run summary.
                'title' => 'Rotate '.self::CANARY_KEY,
                'description' => 'The old one was '.self::CANARY_KEY.' - replace it everywhere.',
            ]))
            ->push($this->assistantText('Created it.'))
            ->whenEmpty(Http::response($this->assistantText('Nothing further.')))]);

        $finished = $this->drive($run, 'Note the key rotation.');

        $records = AiToolRun::withoutWorkspaceScope()->where('ai_run_id', $finished->getKey())->get();

        $this->assertNotEmpty($records);

        foreach ($records as $record) {
            $this->assertStringNotContainsString(
                self::CANARY_KEY,
                json_encode($record->arguments, JSON_THROW_ON_ERROR),
                'A credential-shaped argument was stored verbatim on the audit row.',
            );

            $this->assertStringNotContainsString(
                self::CANARY_KEY,
                (string) $record->result_summary,
                'The tool summary carried the credential onto the audit row.',
            );

            $this->assertStringNotContainsString(self::CANARY_KEY, (string) $record->error);
        }

        // The run's closing report is assembled from those summaries, so it inherits the rule.
        $this->assertStringNotContainsString(self::CANARY_KEY, (string) $finished->summary);
        $this->assertStringNotContainsString(self::CANARY_KEY, (string) $finished->error);

        $this->assertNothingLeaked('a tool argument containing a credential', includeDomainRows: false);
    }

    /* ------------------------------------------------------------------ *
     * Staging
     * ------------------------------------------------------------------ */

    private function stage(): AiRun
    {
        $this->workspace = $this->makeWorkspace(['timezone' => 'UTC']);
        $this->actor = $this->makeMember($this->workspace, WorkspaceRole::Owner);

        $this->provider = AiProvider::factory()->active()->create([
            'name' => 'Test provider',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'api_key' => self::CANARY_KEY,
            'headers' => ['X-Planvio-Canary' => self::CANARY_HEADER],
        ]);

        AiSetting::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'is_enabled' => true,
            'ai_provider_id' => $this->provider->getKey(),
            'default_mode' => AiMode::Autonomous,
            'autonomous_enabled' => true,
        ]);

        $this->project = $this->makeProject($this->workspace, [], [
            'key' => 'WEB',
            'name' => 'Marketing Campaign',
            'slug' => 'marketing-campaign',
        ]);

        TaskStatus::factory()->for($this->project)->inCategory(StatusCategory::Todo)->asDefault()->create(['name' => 'To Do']);
        TaskStatus::factory()->for($this->project)->inCategory(StatusCategory::Done)->create(['name' => 'Done']);

        $this->project = $this->project->fresh();

        return AiRun::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'user_id' => $this->actor->getKey(),
            'trigger' => 'chat',
            'mode' => AiMode::Autonomous,
            'status' => AiRunStatus::Queued,
        ]);
    }

    private function drive(AiRun $run, string $objective): AiRun
    {
        $runner = app(AgentRunner::class);

        return $runner->run($runner->contextFor($run), $objective)->fresh() ?? $run;
    }

    /* ------------------------------------------------------------------ *
     * The sweep
     * ------------------------------------------------------------------ */

    /**
     * Read back everything the failure could have been written into and assert the canaries
     * are in none of it.
     */
    private function assertNothingLeaked(string $scenario, bool $includeDomainRows = true): void
    {
        // Force anything buffered to disk before it is read.
        Log::channel('canary')->info('canary sweep for: '.$scenario);

        $log = is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';

        foreach ([self::CANARY_KEY, self::CANARY_HEADER] as $canary) {
            $this->assertStringNotContainsString(
                $canary,
                $log,
                "The log file carried a credential after {$scenario}.",
            );
        }

        $sources = [
            'ai_runs' => AiRun::withoutWorkspaceScope()->get()->toArray(),
            'ai_tool_runs' => AiToolRun::withoutWorkspaceScope()->get()->toArray(),
            'ai_messages' => AiMessage::query()->get()->toArray(),
            'notifications' => DB::table('notifications')->get()->toArray(),
        ];

        if ($includeDomainRows) {
            $sources['activities'] = Activity::withoutWorkspaceScope()->get()->toArray();
            $sources['audit_logs'] = AuditLog::query()->get()->toArray();
        }

        foreach ($sources as $table => $rows) {
            $encoded = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            foreach ([self::CANARY_KEY, self::CANARY_HEADER] as $canary) {
                $this->assertStringNotContainsString(
                    $canary,
                    $encoded,
                    "A row in {$table} carried a credential after {$scenario}.",
                );
            }
        }
    }

    /* ------------------------------------------------------------------ *
     * The faked provider
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolCall(string $name, array $arguments): array
    {
        return $this->completion([
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR),
                ],
            ]],
        ], 'tool_calls');
    }

    /**
     * @return array<string, mixed>
     */
    private function assistantText(string $content): array
    {
        return $this->completion(['role' => 'assistant', 'content' => $content], 'stop');
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function completion(array $message, string $finishReason): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'gpt-4o-mini',
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finishReason]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
        ];
    }
}
