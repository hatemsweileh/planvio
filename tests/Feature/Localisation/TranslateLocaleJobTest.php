<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Jobs\TranslateLocaleJob;
use App\Models\AiProvider;
use App\Models\AiSetting;
use App\Models\Locale;
use App\Services\Translation\TranslationCatalogue;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The AI first pass: what it stores, what it throws away, and when it will not run at all.
 *
 * The catalogue is a sandbox holding two strings rather than the product's three thousand, so
 * a batch is one request with a known shape and the assertions are about behaviour instead of
 * about which of four hundred lines came back. One of the two carries `:count`, because the
 * placeholder check is the property this job exists to guarantee.
 */
final class TranslateLocaleJobTest extends TestCase
{
    use RefreshDatabase;

    private const COUNTED = 'Overdue by :count sprockets';

    private const PLAIN = 'Sprocket registry';

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.enabled' => true]);

        $this->sandbox = str_replace(DIRECTORY_SEPARATOR, '/', storage_path('framework/testing/translate-'.uniqid()));

        File::ensureDirectoryExists($this->sandbox.'/resources');
        File::ensureDirectoryExists($this->sandbox.'/lang');
        File::put(
            $this->sandbox.'/resources/screen.blade.php',
            "{{ __('".self::PLAIN."') }} {{ __('".self::COUNTED."') }}",
        );

        // No `lang/en` inside the sandbox, so the scan finds no group files and the catalogue
        // is exactly the two literal strings above.
        $this->app->instance(TranslationCatalogue::class, new TranslationCatalogue(
            new Filesystem,
            $this->app->make('translation.loader'),
            $this->sandbox,
            [$this->sandbox.'/lang'],
            'en',
        ));

        Locale::query()->create([
            'code' => 'xx',
            'name' => 'Testish',
            'native_name' => 'Testish',
            'direction' => Locale::LTR,
            'is_enabled' => true,
            'is_default' => false,
            'position' => 9,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ *
     * What comes back
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_translation_that_lost_a_placeholder_is_discarded_and_never_stored(): void
    {
        $this->configureAi();

        // One fair translation, and one that is fluent and has silently dropped `:count` —
        // exactly the failure nothing at run time would ever report.
        $this->fakeAnswers([
            self::PLAIN => 'Registro de piezas',
            self::COUNTED => 'Con retraso de piezas',
        ]);

        TranslateLocaleJob::dispatchSync('xx');

        $this->assertDatabaseHas('translations', [
            'locale' => 'xx',
            'key' => self::PLAIN,
            'value' => 'Registro de piezas',
        ]);

        $this->assertDatabaseMissing('translations', [
            'locale' => 'xx',
            'key' => self::COUNTED,
            'value' => 'Con retraso de piezas',
        ]);

        // Nothing at all was written for the broken line, so it is still listed as
        // untranslated rather than sitting in the table looking finished.
        $this->assertSame(
            [self::COUNTED],
            $this->app->make(TranslationCatalogue::class)->missing('xx')['*'] ?? [],
        );

        $progress = TranslateLocaleJob::progress('xx');

        $this->assertSame(TranslateLocaleJob::FINISHED, $progress['status']);
        $this->assertSame(2, $progress['processed']);
        $this->assertSame(1, $progress['stored']);
        $this->assertSame(1, $progress['discarded']);
    }

    #[Test]
    public function nothing_the_model_writes_is_ever_marked_reviewed(): void
    {
        $this->configureAi();

        $this->fakeAnswers([
            self::PLAIN => 'Registro de piezas',
            self::COUNTED => 'Con retraso de :count piezas',
        ]);

        TranslateLocaleJob::dispatchSync('xx');

        $this->assertSame(2, (int) TranslateLocaleJob::progress('xx')['stored']);

        $this->assertDatabaseCount('translations', 2);
        $this->assertDatabaseMissing('translations', ['locale' => 'xx', 'is_reviewed' => true]);
    }

    #[Test]
    public function an_answer_that_is_not_json_stores_nothing_and_does_not_fail_the_pass(): void
    {
        $this->configureAi();

        Http::fake(['api.openai.com/*' => Http::response($this->completion('I would rather not.'))]);

        TranslateLocaleJob::dispatchSync('xx');

        $this->assertDatabaseCount('translations', 0);

        $progress = TranslateLocaleJob::progress('xx');

        $this->assertSame(TranslateLocaleJob::FINISHED, $progress['status']);
        $this->assertSame(2, $progress['discarded']);
    }

    /* ------------------------------------------------------------------ *
     * When it will not run
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_kill_switch_refuses_the_pass_before_a_single_request(): void
    {
        $this->configureAi();

        AiSetting::query()->whereNull('workspace_id')->update(['kill_switch_engaged' => true]);

        // No Http::fake at all: `preventStrayRequests()` turns any provider call into a
        // failure, so reaching the endpoint would fail this test rather than pass it quietly.
        TranslateLocaleJob::dispatchSync('xx');

        $this->assertDatabaseCount('translations', 0);
        $this->assertSame(TranslateLocaleJob::FAILED, TranslateLocaleJob::progress('xx')['status']);
    }

    #[Test]
    public function it_refuses_when_the_installation_switch_is_off(): void
    {
        $this->configureAi();

        config(['ai.enabled' => false]);

        $this->assertFalse(TranslateLocaleJob::isAvailable());
        $this->assertSame(__('ai.gate.disabled_globally'), TranslateLocaleJob::refusal());

        TranslateLocaleJob::dispatchSync('xx');

        $this->assertDatabaseCount('translations', 0);
    }

    #[Test]
    public function it_refuses_when_the_global_settings_row_has_ai_switched_off(): void
    {
        $this->configureAi();

        AiSetting::query()->whereNull('workspace_id')->update(['is_enabled' => false]);

        $this->assertFalse(TranslateLocaleJob::isAvailable());
    }

    #[Test]
    public function it_will_not_translate_into_the_language_the_product_is_written_in(): void
    {
        $this->configureAi();

        Locale::query()->create([
            'code' => 'en',
            'name' => 'English',
            'native_name' => 'English',
            'direction' => Locale::LTR,
            'is_enabled' => true,
            'is_default' => true,
            'position' => 0,
        ]);

        TranslateLocaleJob::dispatchSync('en');

        $this->assertDatabaseCount('translations', 0);
        $this->assertSame(TranslateLocaleJob::FAILED, TranslateLocaleJob::progress('en')['status']);
    }

    /* ------------------------------------------------------------------ *
     * Harness
     * ------------------------------------------------------------------ */

    private function configureAi(): void
    {
        $provider = AiProvider::factory()->default()->create();

        AiSetting::factory()->create([
            'workspace_id' => null,
            'is_enabled' => true,
            'ai_provider_id' => $provider->getKey(),
        ]);
    }

    /**
     * Answer whatever the job actually asked about.
     *
     * The ids are assigned by the job in catalogue order, so a fixture keyed by id would be
     * asserting the sort order of the scanner rather than the behaviour under test. Reading the
     * request back also proves the English reached the model in the first place.
     *
     * @param array<string, string> $answers English source => translation
     */
    private function fakeAnswers(array $answers): void
    {
        Http::fake(['api.openai.com/*' => function (Request $request) use ($answers): mixed {
            $reply = [];

            foreach ($this->linesSent($request) as $id => $text) {
                if (array_key_exists($text, $answers)) {
                    $reply[(string) $id] = $answers[$text];
                }
            }

            return Http::response($this->completion((string) json_encode($reply, JSON_UNESCAPED_UNICODE)));
        }]);
    }

    /**
     * The id => English map the job put in its user message.
     *
     * @return array<string, string>
     */
    private function linesSent(Request $request): array
    {
        $content = '';

        foreach ($request->data()['messages'] ?? [] as $message) {
            if (($message['role'] ?? null) === 'user') {
                $content = (string) ($message['content'] ?? '');
            }
        }

        $start = strpos($content, '[');
        $decoded = $start === false
            ? null
            : json_decode(substr($content, $start), true);

        $lines = [];

        foreach (is_array($decoded) ? $decoded : [] as $line) {
            $lines[(string) $line['id']] = (string) $line['text'];
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function completion(string $content): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'gpt-4o-mini',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 90, 'completion_tokens' => 30],
        ];
    }
}
