<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Models\Locale;
use App\Services\Translation\TranslationCatalogue;
use App\Services\Translation\TranslationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The five `lang:*` commands, end to end.
 *
 * `lang:scan` is asserted against a copy of `lang/` rather than against the real one: a test
 * that rewrote the shipped catalogue would be a test that edits the product every time it
 * runs.
 */
final class LangCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        // Forward slashes throughout: these paths are handed to Artisan, and a Windows
        // backslash is an escape character to the console input parser.
        $this->sandbox = str_replace(DIRECTORY_SEPARATOR, '/', storage_path('framework/testing/lang-'.uniqid()));

        File::ensureDirectoryExists($this->sandbox);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ *
     * lang:scan
     * ------------------------------------------------------------------ */

    #[Test]
    public function scan_writes_every_literal_key_mapped_to_itself(): void
    {
        $path = $this->useSandboxLang();

        $this->artisan('lang:scan')->assertSuccessful();

        $catalogue = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($catalogue);
        $this->assertGreaterThan(3000, count($catalogue));

        // A key the shell renders on every page, mapped to itself so English is unchanged.
        $this->assertSame('Collapse sidebar', $catalogue['Collapse sidebar'] ?? null);
        $this->assertSame('My Tasks', $catalogue['My Tasks'] ?? null);

        foreach ($catalogue as $key => $value) {
            $this->assertSame($key, $value, 'lang:scan must map each key to itself, not invent text.');
        }
    }

    #[Test]
    public function scan_leaves_a_locally_reworded_string_alone(): void
    {
        $path = $this->useSandboxLang();

        File::put($path, json_encode([
            'Collapse sidebar' => 'Fold the sidebar',
            'A string nobody calls any more' => 'A string nobody calls any more',
            'A retired string somebody translated' => 'Something else entirely',
        ], JSON_THROW_ON_ERROR));

        $this->artisan('lang:scan')->assertSuccessful();

        $catalogue = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Fold the sidebar', $catalogue['Collapse sidebar']);
        $this->assertArrayNotHasKey('A string nobody calls any more', $catalogue);
        $this->assertSame('Something else entirely', $catalogue['A retired string somebody translated']);
    }

    #[Test]
    public function scan_writes_nothing_on_a_dry_run(): void
    {
        $path = $this->useSandboxLang();

        File::put($path, '{}');

        $this->artisan('lang:scan --dry-run')->assertSuccessful();

        $this->assertSame('{}', File::get($path));
    }

    /* ------------------------------------------------------------------ *
     * lang:sync, lang:missing
     * ------------------------------------------------------------------ */

    #[Test]
    public function sync_gives_every_enabled_locale_a_row_for_every_key(): void
    {
        $this->seedLocales();

        $this->artisan('lang:sync')->assertSuccessful();

        $total = app(TranslationCatalogue::class)->scan()->total();

        $this->assertSame($total, DB::table('translations')->where('locale', 'ar')->count());
        $this->assertSame($total, DB::table('translations')->where('locale', 'en')->count());

        // Untranslated, so nothing that renders changes.
        $this->assertSame(0, DB::table('translations')->whereNotNull('value')->count());
        $this->assertSame('Create project', __('Create project'));

        // And running it again is free.
        $this->artisan('lang:sync')->assertSuccessful();

        $this->assertSame($total * 2, DB::table('translations')->count());
    }

    #[Test]
    public function sync_skips_a_disabled_locale(): void
    {
        $this->seedLocales();

        Locale::query()->where('code', 'ar')->update(['is_enabled' => false]);

        $this->artisan('lang:sync')->assertSuccessful();

        $this->assertSame(0, DB::table('translations')->where('locale', 'ar')->count());
        $this->assertGreaterThan(0, DB::table('translations')->where('locale', 'en')->count());
    }

    #[Test]
    public function sync_never_overwrites_a_translation_and_prune_never_deletes_one(): void
    {
        $this->seedLocales();

        app(TranslationRepository::class)->put('ar', null, 'Create project', 'إنشاء مشروع');
        app(TranslationRepository::class)->put('ar', null, 'A string nobody calls any more', 'شيء ما');
        app(TranslationRepository::class)->put('ar', null, 'An untranslated leftover', null);

        $this->artisan('lang:sync --prune')->assertSuccessful();

        $this->assertSame('إنشاء مشروع', DB::table('translations')
            ->where('locale', 'ar')->where('key', 'Create project')->value('value'));

        $this->assertSame('شيء ما', DB::table('translations')
            ->where('locale', 'ar')->where('key', 'A string nobody calls any more')->value('value'));

        $this->assertNull(DB::table('translations')
            ->where('locale', 'ar')->where('key', 'An untranslated leftover')->first());
    }

    #[Test]
    public function missing_lists_what_a_language_cannot_say_yet(): void
    {
        $this->seedLocales();

        // Against a copy of `lang/` that holds English only, so the assertion measures the
        // command rather than how far the shipped Arabic has got: `lang/ar/auth.php` is
        // translated, and a test that reads it would report nothing missing the moment a
        // language is finished — which is the case this command exists to detect the end of.
        $this->useSandboxLang();

        // The `auth` group is three keys, which is a listing a terminal assertion can hold.
        $this->artisan('lang:missing ar --group=auth --all')
            ->expectsOutputToContain('failed')
            ->expectsOutputToContain('throttle')
            ->assertSuccessful();

        foreach (['failed' => 'فشل', 'password' => 'كلمة المرور', 'throttle' => 'محاولات كثيرة'] as $key => $value) {
            app(TranslationRepository::class)->put('ar', 'auth', $key, $value);
        }

        $this->artisan('lang:missing ar --group=auth')
            ->expectsOutputToContain('Nothing is missing')
            ->assertSuccessful();
    }

    /* ------------------------------------------------------------------ *
     * lang:export, lang:import
     * ------------------------------------------------------------------ */

    #[Test]
    public function export_and_import_round_trip(): void
    {
        $this->seedLocales();

        // Against a copy of `lang/` that holds English only: the round trip is about what
        // the database carries, and the shipped Arabic would otherwise fill in the gap the
        // "exports as an empty string" assertion below depends on.
        $this->useSandboxLang();

        app(TranslationRepository::class)->put('ar', null, 'Create project', 'إنشاء مشروع');
        app(TranslationRepository::class)->put('ar', 'enums', 'priority.high', 'عالية');

        $exported = $this->sandbox.'/ar.json';

        $this->artisan('lang:export', ['locale' => 'ar', '--output' => $exported])->assertSuccessful();

        $document = json_decode(File::get($exported), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('إنشاء مشروع', $document['*']['Create project']);
        $this->assertSame('عالية', $document['enums']['priority.high']);

        // Untranslated keys export as empty strings, so a translator has a template rather
        // than a file that looks finished.
        $this->assertSame('', $document['*']['My Tasks']);

        DB::table('translations')->delete();
        app(TranslationRepository::class)->flush();

        $this->artisan('lang:import', ['locale' => 'ar', 'file' => $exported])->assertSuccessful();

        $this->app->setLocale('ar');

        $this->assertSame('إنشاء مشروع', __('Create project'));
        $this->assertSame('عالية', __('enums.priority.high'));

        // The empty strings came back as "known, untranslated" rather than as blank text.
        $this->assertSame('My Tasks', __('My Tasks'));
        $this->assertNull(DB::table('translations')->where('locale', 'ar')->where('key', 'My Tasks')->value('value'));
    }

    #[Test]
    public function import_accepts_a_flat_file_for_one_group(): void
    {
        $this->seedLocales();

        $file = $this->sandbox.'/flat.json';

        File::put($file, json_encode(['priority.high' => 'عالية'], JSON_THROW_ON_ERROR));

        $this->artisan('lang:import', ['locale' => 'ar', 'file' => $file, '--group' => 'enums'])->assertSuccessful();

        $this->app->setLocale('ar');

        $this->assertSame('عالية', __('enums.priority.high'));
    }

    #[Test]
    public function import_refuses_a_document_that_mixes_shapes(): void
    {
        $file = $this->sandbox.'/mixed.json';

        File::put($file, json_encode(['*' => ['a' => 'b'], 'Loose' => 'string'], JSON_THROW_ON_ERROR));

        $this->artisan('lang:import', ['locale' => 'ar', 'file' => $file])->assertFailed();

        $this->assertSame(0, DB::table('translations')->count());
    }

    #[Test]
    public function import_reports_a_file_it_cannot_read(): void
    {
        $this->artisan('lang:import', ['locale' => 'ar', 'file' => $this->sandbox.'/nothing-here.json'])->assertFailed();

        File::put($this->sandbox.'/broken.json', '{not json');

        $this->artisan('lang:import', ['locale' => 'ar', 'file' => $this->sandbox.'/broken.json'])->assertFailed();
    }

    /* ------------------------------------------------------------------ *
     * Harness
     * ------------------------------------------------------------------ */

    /**
     * Point `lang_path()` at a throwaway copy of the shipped catalogue and return the path
     * `lang:scan` will write to.
     */
    private function useSandboxLang(): string
    {
        File::copyDirectory(lang_path('en'), $this->sandbox.'/en');

        $this->app->useLangPath($this->sandbox);

        return $this->sandbox.'/en.json';
    }

    private function seedLocales(): void
    {
        Locale::query()->create([
            'code' => 'en', 'name' => 'English', 'native_name' => 'English',
            'direction' => Locale::LTR, 'is_enabled' => true, 'is_default' => true, 'position' => 0,
        ]);

        Locale::query()->create([
            'code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية',
            'direction' => Locale::RTL, 'is_enabled' => true, 'is_default' => false, 'position' => 1,
        ]);
    }
}
