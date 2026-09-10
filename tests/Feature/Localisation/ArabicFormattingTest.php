<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Enums\WorkspaceRole;
use App\Models\Locale;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use App\Support\Formats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two halves of the formatting decision recorded in docs/LOCALISATION.md §8.
 *
 * **Digits are Latin in every language.** That is not a property the type system can hold,
 * because it is a property of what reaches the screen: one `Illuminate\Support\Number` call
 * or one `IntlDateFormatter` added later would emit `١٬٢٣٤` under an `ar` locale with no
 * error, no warning and nothing to notice — a mixed-numeral interface, which is worse than
 * either choice made consistently. So the rule is asserted on rendered output, and a
 * dedicated case is kept on the two APIs that would break it.
 *
 * **Month and weekday names are Arabic.** The other half of the same decision, and equally
 * silent when it regresses: `->format()` and `->translatedFormat()` are one character apart
 * and the first answers in English however the page is rendered.
 */
final class ArabicFormattingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Both Arabic-Indic ranges. `٠-٩` is what Egypt and the Gulf set; `۰-۹` is the extended
     * set Carbon's regional `ar_*` catalogues carry under `alt_numbers`.
     */
    private const ARABIC_INDIC = '/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u';

    private Workspace $workspace;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLocales();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme', 'locale' => 'ar', 'date_format' => 'Y-m-d']);
        $this->user = $this->makeMember($this->workspace, WorkspaceRole::Owner, ['locale' => 'ar']);

        App::setLocale('ar');
        app(CurrentWorkspace::class)->set($this->workspace);
        Formats::flush();
    }

    protected function tearDown(): void
    {
        Formats::flush();

        parent::tearDown();
    }

    #[Test]
    public function numbers_are_written_in_latin_digits_in_arabic(): void
    {
        $this->assertSame('1,234,567', Formats::number(1234567));
        $this->assertSame('1,234.5', Formats::decimal(1234.5));
        $this->assertSame('0', Formats::number(0));
        $this->assertSame('-42', Formats::number(-42));
    }

    /**
     * The month names come from Carbon, and Carbon follows `App::setLocale()` only because
     * `Carbon\Laravel\ServiceProvider` — registered by Composer package discovery, not by
     * this application — listens for `LocaleUpdated`. That is a real dependency on a
     * third-party provider being registered, and its failure is silent: every date in the
     * product would quietly render `9 September 2026` inside an Arabic page. Asserted here
     * so that a `dont-discover` entry or a stale `bootstrap/cache/packages.php` fails the
     * build instead.
     */
    #[Test]
    public function carbons_locale_follows_the_application_locale(): void
    {
        App::setLocale('ar');

        $this->assertSame('ar', CarbonImmutable::getLocale());
        $this->assertSame('ar', Carbon::getLocale());

        App::setLocale('en');

        $this->assertSame('en', CarbonImmutable::getLocale());
    }

    #[Test]
    public function dates_are_written_in_latin_digits_with_arabic_month_names(): void
    {
        $date = Carbon::parse('2026-09-09');

        $this->assertSame('9 سبتمبر 2026', Formats::using($date, 'j M Y'));
        $this->assertSame('الأربعاء 9 سبتمبر 2026', Formats::using($date, 'l j M Y'));

        // The whole point: named in Arabic, numbered in Latin.
        $this->assertDoesNotMatchRegularExpression(self::ARABIC_INDIC, Formats::using($date, 'l j F Y'));
    }

    #[Test]
    public function a_comma_inside_an_arabic_date_is_an_arabic_comma(): void
    {
        $date = Carbon::parse('2026-09-09');

        $this->assertSame('أربعاء، 9 سبتمبر 2026', Formats::using($date, 'D, j M Y'));

        App::setLocale('en');

        $this->assertSame('Wed, 9 Sep 2026', Formats::using($date, 'D, j M Y'));
    }

    #[Test]
    public function the_workspace_date_format_is_what_a_date_value_is_written_in(): void
    {
        $date = Carbon::parse('2026-09-09 14:35:00');

        $this->assertSame('2026-09-09', Formats::date($date));
        $this->assertSame('2026-09-09 14:35', Formats::dateTime($date));

        $this->workspace->forceFill(['date_format' => 'd/m/Y'])->save();
        Formats::flush();

        $this->assertSame('09/09/2026', Formats::date($date));
    }

    #[Test]
    public function the_locale_overrides_the_workspace_when_it_names_a_format(): void
    {
        $date = Carbon::parse('2026-09-09');

        Locale::query()->where('code', 'ar')->update(['date_format' => 'j F Y']);
        Formats::flush();

        $this->assertSame('9 سبتمبر 2026', Formats::date($date));

        // Blank is "no opinion", not "render nothing".
        Locale::query()->where('code', 'ar')->update(['date_format' => '']);
        Formats::flush();

        $this->assertSame('2026-09-09', Formats::date($date));
    }

    #[Test]
    public function the_week_opens_on_the_day_the_workspace_chose(): void
    {
        $this->workspace->forceFill(['week_starts_on' => 1])->save();

        $this->assertSame(1, Formats::weekStartsOn($this->workspace));

        $this->workspace->forceFill(['week_starts_on' => 6])->save();

        $this->assertSame(6, Formats::weekStartsOn($this->workspace));
    }

    #[Test]
    public function the_locale_overrides_the_workspace_when_it_names_a_week_start(): void
    {
        $this->workspace->forceFill(['week_starts_on' => 1])->save();

        Locale::query()->where('code', 'ar')->update(['first_day_of_week' => 6]);
        Formats::flush();

        $this->assertSame(6, Formats::weekStartsOn($this->workspace));

        // Null is "inherit from the workspace", which is what the admin form promises.
        Locale::query()->where('code', 'ar')->update(['first_day_of_week' => null]);
        Formats::flush();

        $this->assertSame(1, Formats::weekStartsOn($this->workspace));
    }

    #[Test]
    public function sunday_is_a_week_start_and_not_an_absent_one(): void
    {
        // The whole reason this is a range check rather than `?:`. Sunday is 0, and a
        // truthiness test would quietly promote every Sunday-first installation to Monday
        // while looking like it was reading the setting.
        $this->workspace->forceFill(['week_starts_on' => 3])->save();

        Locale::query()->where('code', 'ar')->update(['first_day_of_week' => 0]);
        Formats::flush();

        $this->assertSame(0, Formats::weekStartsOn($this->workspace));

        $this->workspace->forceFill(['week_starts_on' => 0])->save();
        Locale::query()->where('code', 'ar')->update(['first_day_of_week' => null]);
        Formats::flush();

        $this->assertSame(0, Formats::weekStartsOn($this->workspace));
    }

    #[Test]
    public function a_week_start_outside_the_week_is_ignored_rather_than_clamped(): void
    {
        $this->workspace->forceFill(['week_starts_on' => 6])->save();

        Locale::query()->where('code', 'ar')->update(['first_day_of_week' => 9]);
        Formats::flush();

        // Not Saturday-clamped-from-9 by accident: the workspace's own answer, which is
        // the one an administrator can see and correct.
        $this->assertSame(6, Formats::weekStartsOn($this->workspace));
    }

    #[Test]
    public function the_week_start_a_language_prefers_does_not_change_what_the_workspace_stores(): void
    {
        // The reading preference and the workspace's answer to "when does our week start"
        // are different questions. DateResolver and the AI tools read the second, so that
        // "what is due next week" cannot depend on the language it was asked in.
        $this->workspace->forceFill(['week_starts_on' => 1])->save();

        Locale::query()->where('code', 'ar')->update(['first_day_of_week' => 6]);
        Formats::flush();

        $this->assertSame(6, Formats::weekStartsOn($this->workspace));
        $this->assertSame(1, (int) $this->workspace->fresh()->week_starts_on);
    }

    #[Test]
    public function a_missing_date_renders_a_placeholder_rather_than_an_empty_cell(): void
    {
        $this->assertSame(Formats::MISSING, Formats::date(null));
        $this->assertSame('', Formats::date(null, ''));
    }

    #[Test]
    public function no_arabic_indic_digit_reaches_an_arabic_page(): void
    {
        $project = $this->makeProject($this->workspace);
        $this->makeTask($project, ['created_by' => $this->user->id, 'reporter_id' => $this->user->id]);

        $paths = [
            "/w/{$this->workspace->slug}",
            "/w/{$this->workspace->slug}/projects",
            "/w/{$this->workspace->slug}/my-tasks",
            "/w/{$this->workspace->slug}/reports",
            "/w/{$this->workspace->slug}/settings",
        ];

        foreach ($paths as $path) {
            $response = $this->actingInWorkspace($this->user->fresh(), $this->workspace)->get($path);

            $this->assertSame(200, $response->getStatusCode(), "{$path} did not render in Arabic.");

            $this->assertDoesNotMatchRegularExpression(
                self::ARABIC_INDIC,
                (string) $response->getContent(),
                "{$path} rendered an Arabic-Indic digit. Planvio writes numbers in Latin digits in "
                .'every language (docs/LOCALISATION.md §8) — something is formatting through a '
                .'locale-aware formatter.',
            );
        }
    }

    /**
     * The two APIs that would break the rule, named so that adding one is a failing test
     * rather than a mixed-numeral screen somebody notices in a screenshot months later.
     */
    #[Test]
    public function the_locale_aware_number_formatters_are_not_used(): void
    {
        $offenders = [];

        foreach (['app', 'resources/views'] as $directory) {
            foreach ($this->phpFilesIn(base_path($directory)) as $file) {
                $source = (string) file_get_contents($file);

                if (preg_match('/\b(?:Illuminate\\\\Support\\\\)?Number::|new\s+(?:\\\\)?(?:NumberFormatter|IntlDateFormatter)\b/', $source) === 1) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Illuminate\Support\Number and the intl formatters emit Arabic-Indic digits under an '
            .'`ar` locale. Use App\Support\Formats instead (docs/LOCALISATION.md §8).',
        );
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
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
