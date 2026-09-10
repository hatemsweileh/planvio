<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Services\Translation\DatabaseTranslationLoader;
use App\Services\Translation\TranslationRepository;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The loader that puts the database in front of `lang/`.
 *
 * Four properties, and each of them is here because getting it wrong is expensive:
 * an administrator's edit must beat the shipped file, a grouped key must land inside the
 * nested array rather than beside it, a missing table must not be fatal, and an edit must be
 * visible without anybody being told to clear a cache.
 */
final class DatabaseTranslationLoaderTest extends TestCase
{
    use RefreshDatabase;

    private TranslationRepository $translations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translations = app(TranslationRepository::class);
    }

    #[Test]
    public function the_registered_loader_is_the_database_one(): void
    {
        $this->assertInstanceOf(DatabaseTranslationLoader::class, app('translation.loader'));
        $this->assertInstanceOf(DatabaseTranslationLoader::class, app(Loader::class));
    }

    #[Test]
    public function a_stored_line_beats_the_shipped_file(): void
    {
        $this->assertSame('High', __('enums.priority.high'));

        $this->translations->put('en', 'enums', 'priority.high', 'Critical');

        $this->assertSame('Critical', __('enums.priority.high'));

        // Only the one line: the rest of the group still comes from the file.
        $this->assertSame('Low', __('enums.priority.low'));
    }

    #[Test]
    public function a_stored_line_beats_the_json_catalogue(): void
    {
        $this->assertSame('Create project', __('Create project'));

        $this->translations->put('en', null, 'Create project', 'Start a project');

        $this->assertSame('Start a project', __('Create project'));
    }

    #[Test]
    public function an_edit_is_visible_without_a_manual_flush(): void
    {
        // Load first, so the translator and both cache tiers are holding the old value.
        $this->assertSame('Create project', __('Create project'));

        $this->translations->put('en', null, 'Create project', 'New project');

        $this->assertSame('New project', __('Create project'));

        $this->translations->put('en', null, 'Create project', 'Another project');

        $this->assertSame('Another project', __('Create project'));
    }

    #[Test]
    public function an_untranslated_row_never_blanks_the_shipped_text(): void
    {
        // This is what `lang:sync` writes: the key exists, nobody has translated it.
        $this->translations->put('en', null, 'Create project', null);

        $this->assertSame('Create project', __('Create project'));
    }

    #[Test]
    public function a_missing_translations_table_is_not_fatal(): void
    {
        // The installer renders localised screens before `migrate` has run, and the loader is
        // resolved during boot. Neither table exists at that point — not `translations`, and
        // not the `cache` table the version stamp is read from — so both are taken away here.
        Schema::drop('translations');
        Schema::drop('cache');

        $loader = new DatabaseTranslationLoader(
            app('files'),
            [lang_path()],
            new TranslationRepository,
        );

        $json = $loader->load('en', '*', '*');

        $this->assertSame('Create project', $json['Create project'] ?? null);
        $this->assertSame('High', $loader->load('en', 'enums')['priority']['high']);
        $this->assertSame([], $loader->load('en', 'nothing-of-the-sort'));
    }

    #[Test]
    public function the_json_catalogue_falls_back_to_the_source_language(): void
    {
        // `AI` is a one-word key and `lang/en/ai.php` exists, so without a JSON fallback
        // Laravel re-parses it as a group name and hands back the entire file as an array —
        // which Blade then tries to escape. Rendering the English is the whole point.
        //
        // Asserted against a language this installation ships nothing for, rather than
        // against Arabic: `lang/ar.json` is translated, and a gap is what this test is about.
        $this->app->setLocale('de');

        $this->assertSame('AI', __('AI'));
        $this->assertIsString(__('Search'));
        $this->assertSame('Create project', __('Create project'));

        $this->translations->put('de', null, 'Create project', 'Projekt anlegen');

        $this->assertSame('Projekt anlegen', __('Create project'));
    }

    #[Test]
    public function the_locale_only_view_does_not_borrow_from_the_source_language(): void
    {
        /** @var DatabaseTranslationLoader $loader */
        $loader = app('translation.loader');

        // A language this installation ships nothing for, so the only thing that could put
        // the key in either result is the fallback that `load()` applies and
        // `loadForLocale()` deliberately does not.
        $this->assertArrayNotHasKey('Create project', $loader->loadForLocale('de', '*', '*'));
        $this->assertArrayHasKey('Create project', $loader->load('de', '*', '*'));
    }
}
