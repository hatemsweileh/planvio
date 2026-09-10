<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Services\Translation\TranslationCatalogue;
use App\Services\Translation\TranslationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The scanner that turns "the source calls `__()` five thousand times" into a catalogue.
 *
 * The extraction rules carry most of the weight here. A literal is a key; a key built by
 * concatenation is not, and must be counted rather than half-recovered; and a dotted key is
 * only a group key when the group file exists — get that last one wrong and `lang:scan`
 * writes `auth.failed` into `en.json`, where it shadows the file it was addressing and puts
 * the raw key on the sign-in screen.
 */
final class TranslationCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private TranslationCatalogue $catalogue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalogue = app(TranslationCatalogue::class);
    }

    #[Test]
    public function it_finds_a_key_that_is_known_to_be_in_the_source(): void
    {
        $scan = $this->catalogue->scan();

        // Rendered by the shell on every page of the product.
        $this->assertContains('Collapse sidebar', $scan->json);
        $this->assertContains('My Tasks', $scan->json);

        // A one-word key that collides with lang/en/ai.php, which is why it has to be listed.
        $this->assertContains('AI', $scan->json);
    }

    #[Test]
    public function the_catalogue_is_the_size_the_product_is(): void
    {
        $scan = $this->catalogue->scan();

        $this->assertGreaterThan(3000, count($scan->json));
        $this->assertGreaterThan(900, $scan->files);
        $this->assertSame(count($scan->json) + array_sum(array_map('count', $scan->groups)), $scan->total());
    }

    #[Test]
    public function group_keys_are_kept_out_of_the_json_catalogue(): void
    {
        $scan = $this->catalogue->scan();

        foreach (['auth.failed', 'passwords.token', 'actions.tasks.copy_of', 'ai.gate.kill_switch'] as $key) {
            $this->assertNotContains($key, $scan->json, "{$key} addresses a group file and must not be written into en.json.");
        }

        $this->assertContains('failed', $scan->groups['auth'] ?? []);
        $this->assertContains('tasks.copy_of', $scan->groups['actions'] ?? []);
    }

    #[Test]
    public function keys_built_at_runtime_are_counted_rather_than_guessed_at(): void
    {
        $scan = $this->catalogue->scan();

        // `__('enums.priority.'.$this->value)` and its kin. The number is the honest size of
        // what a scan cannot see; claiming a complete catalogue would be the dishonest option.
        $this->assertGreaterThan(0, $scan->dynamic);
        $this->assertNotContains('enums.priority.', $scan->json);
    }

    #[Test]
    #[DataProvider('extractionCases')]
    public function it_reads_the_key_out_of_a_call_site(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->catalogue->keysIn($source));
    }

    /**
     * @return array<string, array{0: string, 1: array<int, string|null>}>
     */
    public static function extractionCases(): array
    {
        return [
            'single quoted' => ["__('Create project')", ['Create project']],
            'double quoted' => ['__("Create project")', ['Create project']],
            'with replacements' => ["__('Hello :name', ['name' => \$n])", ['Hello :name']],
            'escaped quote' => ["__('It\\'s late')", ["It's late"]],
            'blade directive' => ["@lang('Create project')", ['Create project']],
            'plural' => ["trans_choice('{1}:count task|[2,*]:count tasks', 2)", ['{1}:count task|[2,*]:count tasks']],
            'across lines' => ["__(\n    'Create project',\n)", ['Create project']],
            'concatenation is dynamic' => ["__('enums.priority.'.\$value)", [null]],
            'variable is dynamic' => ['__($key)', [null]],
            'interpolation is dynamic' => ['__("Hello $name")', [null]],
            'a method named trans is not the translator' => ['$formatter->trans("x")', []],
            'a static call is not the translator' => ['Money::trans("x")', []],
        ];
    }

    #[Test]
    public function english_is_complete_and_a_fresh_language_is_not(): void
    {
        // Not asserted at exactly 100: every string added to the product is missing from
        // `lang/en.json` until somebody runs `lang:scan`, and a suite that went red on the
        // first new sentence would train people to stop reading it. A large gap is a real
        // failure, and this is where it shows up.
        $this->assertGreaterThanOrEqual(
            90.0,
            $this->catalogue->completion('en'),
            'lang/en.json has fallen behind the source. Run: php artisan lang:scan',
        );

        $this->assertSame(0.0, $this->catalogue->completion('zz'));
        $this->assertNotSame([], $this->catalogue->missing('zz'));
    }

    #[Test]
    public function a_stored_translation_counts_towards_completion(): void
    {
        $before = $this->catalogue->missing('zz')['*'];

        app(TranslationRepository::class)->put('zz', null, 'Create project', 'Kreye pwoje');

        $after = $this->catalogue->missing('zz')['*'];

        $this->assertContains('Create project', $before);
        $this->assertNotContains('Create project', $after);
        $this->assertCount(count($before) - 1, $after);
    }

    #[Test]
    public function a_translation_identical_to_the_english_still_counts_as_translated(): void
    {
        // "Email" is "Email" in a good many languages. `Translator::has()` decides by
        // comparing the result against the key and would call this one missing forever.
        app(TranslationRepository::class)->put('zz', null, 'Email', 'Email');

        $this->assertNotContains('Email', $this->catalogue->missing('zz')['*']);
    }
}
