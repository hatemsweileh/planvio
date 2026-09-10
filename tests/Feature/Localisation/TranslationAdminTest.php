<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Filament\Pages\Translations;
use App\Filament\Resources\Locales\LocaleResource;
use App\Filament\Resources\Locales\Pages\CreateLocale;
use App\Filament\Resources\Locales\Pages\EditLocale;
use App\Filament\Resources\Locales\Pages\ListLocales;
use App\Models\Locale;
use App\Models\User;
use App\Services\Translation\TranslationCatalogue;
use App\Services\Translation\TranslationRepository;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Adding a language and translating it from `/admin`.
 *
 * The catalogue is a sandbox of two strings, one of them carrying `:count`. That keeps the
 * editor to a single page of known rows, and it puts the placeholder rule — the one thing on
 * this screen that must be impossible rather than discouraged — in front of every save.
 */
final class TranslationAdminTest extends TestCase
{
    use RefreshDatabase;

    private const COUNTED = 'Overdue by :count sprockets';

    private const PLAIN = 'Sprocket registry';

    private User $admin;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($this->admin);

        // The panel middleware does this on every HTTP request; a component driven directly
        // never passes through it.
        Filament::setCurrentPanel('admin');

        $this->sandbox = str_replace(DIRECTORY_SEPARATOR, '/', storage_path('framework/testing/admin-lang-'.uniqid()));

        File::ensureDirectoryExists($this->sandbox.'/resources');
        File::ensureDirectoryExists($this->sandbox.'/lang');
        File::put(
            $this->sandbox.'/resources/screen.blade.php',
            "{{ __('".self::PLAIN."') }} {{ __('".self::COUNTED."') }}",
        );

        $this->app->instance(TranslationCatalogue::class, new TranslationCatalogue(
            new Filesystem,
            $this->app->make('translation.loader'),
            $this->sandbox,
            [$this->sandbox.'/lang'],
            'en',
        ));

        $this->seedLocales();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ *
     * Adding a language
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_adds_a_language_and_seeds_the_catalogue_so_the_editor_opens_on_a_list(): void
    {
        Livewire::test(CreateLocale::class)
            ->fillForm([
                'code' => 'pt-br',
                'name' => 'Portuguese (Brazil)',
                'native_name' => 'Português (Brasil)',
                'direction' => Locale::LTR,
                'is_enabled' => true,
                'is_default' => false,
                'position' => 5,
                'seed_from' => LocaleResource::SEED_CATALOGUE,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // Canonicalised on the way in: `pt-br`, `PT-BR` and `pt-BR` are one language, and the
        // column is unique.
        $this->assertDatabaseHas('locales', [
            'code' => 'pt-BR',
            'name' => 'Portuguese (Brazil)',
            'direction' => Locale::LTR,
            'is_enabled' => true,
            'is_default' => false,
        ]);

        // Seeded: a row per key, untranslated, which is what makes the editor a worklist.
        $this->assertDatabaseHas('translations', ['locale' => 'pt-BR', 'key' => self::PLAIN, 'value' => null]);
        $this->assertDatabaseHas('translations', ['locale' => 'pt-BR', 'key' => self::COUNTED, 'value' => null]);

        // And nothing rendered changed: an untranslated row is exactly what the loader skips.
        $this->app->setLocale('pt-BR');
        $this->assertSame(self::PLAIN, __(self::PLAIN));
    }

    #[Test]
    public function it_refuses_a_code_that_is_not_a_bcp_47_tag(): void
    {
        Livewire::test(CreateLocale::class)
            ->fillForm([
                'code' => 'not a language',
                'name' => 'Nonsense',
                'native_name' => 'Nonsense',
                'direction' => Locale::LTR,
                'seed_from' => LocaleResource::SEED_NONE,
            ])
            ->call('create')
            ->assertHasFormErrors(['code']);

        $this->assertDatabaseCount('locales', 2);
    }

    #[Test]
    public function seeding_from_another_language_copies_its_wording_in_unreviewed(): void
    {
        $translations = $this->app->make(TranslationRepository::class);
        $translations->put('ar', null, self::PLAIN, 'إنشاء مشروع', null, true);

        Livewire::test(CreateLocale::class)
            ->fillForm([
                'code' => 'arz',
                'name' => 'Egyptian Arabic',
                'native_name' => 'مصرى',
                'direction' => Locale::RTL,
                'is_enabled' => true,
                'position' => 6,
                'seed_from' => 'ar',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('translations', [
            'locale' => 'arz',
            'key' => self::PLAIN,
            'value' => 'إنشاء مشروع',
            // Copied wording is a draft in the new language, never an approval of it.
            'is_reviewed' => false,
        ]);
    }

    /* ------------------------------------------------------------------ *
     * The guard rails
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_default_language_cannot_be_switched_off_even_by_a_hand_made_payload(): void
    {
        $english = Locale::query()->where('code', 'en')->firstOrFail();

        Livewire::test(EditLocale::class, ['record' => $english->getKey()])
            ->fillForm(['is_enabled' => false, 'is_default' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $english->refresh();

        $this->assertTrue($english->is_enabled, 'The default language must stay enabled.');
        $this->assertTrue($english->is_default, 'The default flag must not be cleared without a replacement.');
    }

    #[Test]
    public function the_default_language_cannot_be_deleted(): void
    {
        $english = Locale::query()->where('code', 'en')->firstOrFail();
        $arabic = Locale::query()->where('code', 'ar')->firstOrFail();

        Livewire::test(EditLocale::class, ['record' => $english->getKey()])
            ->assertActionHidden('delete');

        Livewire::test(EditLocale::class, ['record' => $arabic->getKey()])
            ->assertActionVisible('delete');
    }

    #[Test]
    public function switching_a_language_off_moves_everybody_who_was_on_it_to_the_default(): void
    {
        $arabic = Locale::query()->where('code', 'ar')->firstOrFail();

        $workspace = $this->makeWorkspace(['locale' => 'ar']);
        $reader = User::factory()->create(['locale' => 'ar']);

        Livewire::test(EditLocale::class, ['record' => $arabic->getKey()])
            ->fillForm(['is_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($arabic->fresh()->is_enabled);

        // Left alone, these columns would throw both of them straight back into a language
        // nobody chose the moment it was switched on again.
        $this->assertSame('en', $reader->fresh()->locale);
        $this->assertSame('en', $workspace->fresh()->locale);
    }

    #[Test]
    public function promoting_a_language_moves_the_default_flag_in_one_operation(): void
    {
        $arabic = Locale::query()->where('code', 'ar')->firstOrFail();

        LocaleResource::promote($arabic);

        $this->assertSame(1, Locale::query()->where('is_default', true)->count());
        $this->assertTrue($arabic->fresh()->is_default);
        $this->assertTrue($arabic->fresh()->is_enabled);
        $this->assertFalse(Locale::query()->where('code', 'en')->firstOrFail()->is_default);
    }

    #[Test]
    public function the_list_renders_with_completion_for_every_language(): void
    {
        Livewire::test(ListLocales::class)
            ->assertOk()
            ->assertCanSeeTableRecords(Locale::query()->get());
    }

    #[Test]
    public function every_screen_renders_over_http_for_a_platform_administrator(): void
    {
        $arabic = Locale::query()->where('code', 'ar')->firstOrFail();

        // Loaded rather than only driven as components: the completion bar, the matrix and the
        // import panel are Blade, and a broken one of those would sit there until the day
        // somebody opened the screen.
        foreach ([
            '/admin/locales',
            '/admin/locales/create',
            '/admin/locales/'.$arabic->getKey().'/edit',
            '/admin/translations',
            '/admin/translations?locale=ar',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    #[Test]
    public function nobody_but_a_platform_administrator_may_open_them(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get('/admin/locales')->assertForbidden();
        $this->get('/admin/translations')->assertForbidden();
    }

    /* ------------------------------------------------------------------ *
     * The editor
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_save_that_drops_a_placeholder_is_refused_and_writes_nothing_at_all(): void
    {
        Livewire::test(Translations::class)
            ->set('locale', 'ar')
            ->set('catalogue', '*')
            ->set('values.'.$this->hash(self::PLAIN), 'إنشاء مشروع')
            ->set('values.'.$this->hash(self::COUNTED), 'يستحق خلال أيام')
            ->call('save');

        // The whole save is refused, not the broken line alone: a partial write would leave
        // somebody unable to tell which of their edits had landed.
        $this->assertDatabaseMissing('translations', ['locale' => 'ar', 'key' => self::COUNTED, 'value' => 'يستحق خلال أيام']);
        $this->assertDatabaseMissing('translations', ['locale' => 'ar', 'key' => self::PLAIN, 'value' => 'إنشاء مشروع']);
    }

    #[Test]
    public function a_save_that_keeps_its_placeholders_is_written_and_renders_on_the_next_read(): void
    {
        Livewire::test(Translations::class)
            ->set('locale', 'ar')
            ->set('catalogue', '*')
            ->set('values.'.$this->hash(self::COUNTED), 'يستحق خلال :count أيام')
            ->set('reviewed.'.$this->hash(self::COUNTED), true)
            ->call('save');

        $this->assertDatabaseHas('translations', [
            'locale' => 'ar',
            'key' => self::COUNTED,
            'value' => 'يستحق خلال :count أيام',
            'is_reviewed' => true,
            'updated_by' => $this->admin->getKey(),
        ]);

        // No artisan command in between: the version stamp the loader keys on was bumped by
        // the write itself.
        $this->app->setLocale('ar');
        $this->assertSame('يستحق خلال 3 أيام', __(self::COUNTED, ['count' => 3]));
    }

    #[Test]
    public function clearing_a_translation_falls_the_line_back_to_the_english(): void
    {
        $this->app->make(TranslationRepository::class)->put('ar', null, self::PLAIN, 'إنشاء مشروع');

        Livewire::test(Translations::class)
            ->set('locale', 'ar')
            ->set('catalogue', '*')
            ->set('values.'.$this->hash(self::PLAIN), '')
            ->call('save');

        // Stored as null rather than as an empty string, which would blank the text instead of
        // falling back to it.
        $this->assertDatabaseHas('translations', ['locale' => 'ar', 'key' => self::PLAIN, 'value' => null]);

        $this->app->setLocale('ar');
        $this->assertSame(self::PLAIN, __(self::PLAIN));
    }

    #[Test]
    public function the_untranslated_filter_narrows_the_list(): void
    {
        $this->app->make(TranslationRepository::class)->put('ar', null, self::PLAIN, 'إنشاء مشروع');

        $component = Livewire::test(Translations::class)
            ->set('locale', 'ar')
            ->set('filter', Translations::FILTER_UNTRANSLATED);

        $this->assertSame(1, $component->viewData('matched'));
        $this->assertSame(self::COUNTED, $component->viewData('rows')[0]['key']);
    }

    #[Test]
    public function it_exports_the_language_as_json(): void
    {
        Livewire::test(Translations::class)
            ->set('locale', 'ar')
            ->call('export', false)
            ->assertFileDownloaded('planvio-ar.json');
    }

    /* ------------------------------------------------------------------ *
     * Import
     * ------------------------------------------------------------------ */

    #[Test]
    public function an_import_preview_reports_the_right_counts_and_writes_nothing(): void
    {
        $this->app->make(TranslationRepository::class)->put('ar', null, self::PLAIN, 'إنشاء مشروع');

        $component = Livewire::test(Translations::class)
            ->set('locale', 'ar')
            ->set('upload', $this->file([
                '*' => [
                    // Already stored with exactly this wording.
                    self::PLAIN => 'إنشاء مشروع',
                    // Fluent, and it has dropped `:count`.
                    self::COUNTED => 'يستحق خلال أيام',
                    // Exported from a build that had a string this one does not.
                    'A key this product does not have' => 'أيا كان',
                ],
            ]))
            ->call('previewImport');

        $summary = $component->get('importSummary');

        $this->assertSame(0, $summary['totals']['new']);
        $this->assertSame(0, $summary['totals']['changed']);
        $this->assertSame(1, $summary['totals']['unchanged']);
        $this->assertSame(1, $summary['totals']['rejected']);
        $this->assertSame(1, $summary['totals']['unknown']);
        $this->assertSame(0, $summary['writes']);

        $this->assertSame([':count'], array_map(
            static fn (string $name): string => ':'.$name,
            $summary['rejections'][0]['missing'],
        ));

        // A preview writes nothing. Only the row that was already there exists.
        $this->assertDatabaseCount('translations', 1);
    }

    #[Test]
    public function applying_an_import_writes_the_good_lines_and_leaves_out_the_broken_one(): void
    {
        $component = Livewire::test(Translations::class)
            ->set('locale', 'ar')
            ->set('upload', $this->file([
                '*' => [
                    self::PLAIN => 'إنشاء مشروع',
                    self::COUNTED => 'يستحق خلال أيام',
                ],
            ]))
            ->call('previewImport');

        $this->assertSame(1, $component->get('importSummary')['writes']);

        $component->call('applyImport');

        $this->assertDatabaseHas('translations', ['locale' => 'ar', 'key' => self::PLAIN, 'value' => 'إنشاء مشروع']);
        $this->assertDatabaseMissing('translations', ['locale' => 'ar', 'key' => self::COUNTED]);

        // The staged file is cleared once it has been applied, so a second click cannot
        // re-apply it by accident.
        $this->assertSame([], $component->get('importSummary'));
    }

    #[Test]
    public function a_file_that_is_not_json_is_reported_rather_than_thrown(): void
    {
        $component = Livewire::test(Translations::class)
            ->set('locale', 'ar')
            ->set('upload', UploadedFile::fake()->createWithContent('broken.json', '{not json'))
            ->call('previewImport');

        $this->assertSame([], $component->get('importSummary'));
        $this->assertDatabaseCount('translations', 0);
    }

    /* ------------------------------------------------------------------ *
     * The AI action
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_ai_action_is_not_offered_when_the_ai_layer_is_off(): void
    {
        config(['ai.enabled' => false]);

        Livewire::test(Translations::class)
            ->set('locale', 'ar')
            ->assertActionHidden('translateWithAi');
    }

    /* ------------------------------------------------------------------ *
     * Harness
     * ------------------------------------------------------------------ */

    private function hash(string $key): string
    {
        return TranslationRepository::hash(null, $key);
    }

    /**
     * @param array<string, array<string, string>> $document
     */
    private function file(array $document): UploadedFile
    {
        Storage::fake('local');

        return UploadedFile::fake()->createWithContent(
            'translations.json',
            (string) json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
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
