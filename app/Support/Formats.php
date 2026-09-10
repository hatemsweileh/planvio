<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\SetLocale;
use App\Models\Locale;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\App;
use Throwable;

/**
 * How Planvio writes a date and a number, in every language it renders in.
 *
 * ## Digits are Latin. Always, everywhere, in every language
 *
 * Arabic locales do not agree with each other about numerals: Egypt and the Gulf commonly
 * set Arabic-Indic (١٢٣), the Maghreb sets Latin (123), and plenty of readers of both use
 * whichever their keyboard produces. There is no answer that is simply correct, so this is
 * a decision rather than a lookup, and it is written down here and in docs/LOCALISATION.md
 * rather than left to emerge from whatever each call site happened to reach for.
 *
 * Latin, for reasons that are about this product rather than about the script:
 *
 *  - A task key is `WEB-142` in every language, because it is an identifier and half of it
 *    is Latin already. `WEB-١٤٢` is a different string that identifies the same task, and
 *    the first person to paste one into a search box finds nothing.
 *  - The same figures are exported to CSV and read back by a spreadsheet, sent to the API,
 *    and quoted in an email. Arabic-Indic digits survive none of those round trips
 *    reliably, and a budget that reads one way on screen and another in the export is a
 *    reconciliation problem, not a typographic preference.
 *  - Mixed digit systems inside one interface are the actual failure mode. A count in
 *    Arabic-Indic beside a duration in Latin is worse than either choice made consistently.
 *
 * The decision is enforceable because nothing in Planvio uses a locale-aware number
 * formatter. `number_format()` — what this class wraps, and what the product's views call
 * directly — is documented as locale-independent and always emits ASCII digits with the
 * separators it is given. `Illuminate\Support\Number` and `IntlDateFormatter` are the two
 * that would produce `١٬٢٣٤` under an `ar` locale without anybody asking, and neither is
 * used anywhere in the application. `tests/Feature/Localisation/ArabicFormattingTest.php`
 * holds that line.
 *
 * Carbon cannot leak them either: its Arabic-Indic numerals live under `alt_numbers`, which
 * is reached only by the `OD`/`OM`/`OY`/`OH`/`Oh`/`Om`/`Os` tokens of `isoFormat()` and by
 * `diffForHumans()` with the `altNumbers` option. Planvio uses none of those, and the plain
 * `ar` catalogue this installation resolves to does not define `alt_numbers` at all.
 *
 * ## Months and weekdays are Arabic
 *
 * The names are the part of a date that is language rather than notation, so they are
 * translated: `9 سبتمبر 2026`, not `9 September 2026` and not `٩ سبتمبر ٢٠٢٦`. That comes
 * from Carbon, which follows `App::setLocale()` because its own service provider listens
 * for Laravel's `LocaleUpdated` event — so it follows {@see SetLocale} with nothing further
 * to wire up, and `ArabicFormattingTest` asserts that, because the provider is registered
 * by package discovery rather than by this application and its absence would be silent.
 * `translatedFormat()` is what reads it — `format()` is the PHP builtin and always answers
 * in English.
 *
 * ## Two shapes of date, and which is which
 *
 * A date printed **as a value** — a field, a table cell, a definition list — is written in
 * the format the installation chose, which is what this class returns. That is the only
 * thing `workspaces.date_format` can sensibly govern, and it is why the setting exists.
 *
 * A date printed **inside a sentence** — "Due 9 Sep", "Overdue since 9 Sep" — keeps
 * Carbon's own short form at the call site, because `Due 2026-09-09` is not a sentence and
 * a prose date wants the month named and the year dropped when it is this year. Those sites
 * are already language-aware; they call `translatedFormat()` directly and are deliberately
 * not routed through here.
 *
 * ## Where the format string comes from
 *
 * Most specific first, which is the same order {@see SetLocale} uses
 * for the language itself:
 *
 *   1. `locales.date_format` for the language being rendered — the override an
 *      administrator sets under Admin → Platform → Languages when a language wants a shape
 *      of its own;
 *   2. `workspaces.date_format` for the workspace being viewed;
 *   3. `planvio.defaults.workspace.date_format`, which is what the installer wrote;
 *   4. `Y-m-d`.
 *
 * Each step is skipped when it is blank rather than accepted and then rendered as nothing.
 */
final class Formats
{
    /**
     * The shape a date takes when nothing else has an opinion. ISO 8601, because an
     * unconfigured installation should be unambiguous rather than regional.
     */
    public const FALLBACK_DATE = 'Y-m-d';

    /** Appended to the date pattern when a time of day is wanted. 24-hour, everywhere. */
    public const TIME = 'H:i';

    /**
     * The day a week opens on when nothing else has an opinion. Monday, matching ISO 8601
     * and the installer's own default.
     */
    public const FALLBACK_WEEK_START = 1;

    /** What a null date renders as. An em dash reads as "no value" in both directions. */
    public const MISSING = '—';

    /**
     * Stated rather than left to the defaults of `number_format()`, so that the digits and
     * the separators are one decision recorded in one place.
     */
    public const DECIMAL_POINT = '.';

    public const THOUSANDS_SEPARATOR = ',';

    /**
     * U+2066 LEFT-TO-RIGHT ISOLATE and U+2069 POP DIRECTIONAL ISOLATE. See {@see range()}:
     * they open and close a run whose internal order the surrounding paragraph must not
     * touch, and they are what does that job when the run is assembled inside a translated
     * string rather than in markup.
     */
    public const ISOLATE_START = "\u{2066}";

    public const ISOLATE_END = "\u{2069}";

    /**
     * The per-language override, once looked up, keyed by locale code.
     *
     * A rendered page asks for the pattern once per date printed — a hundred times on a
     * board — and only the locale half of the answer costs a query. The workspace half is
     * read live off a model that is already in memory, so a workspace edited mid-request
     * takes effect immediately and only this narrow lookup is remembered.
     *
     * @var array<string, ?string>
     */
    private static array $localeFormats = [];

    /**
     * The per-language week start, once looked up, keyed by locale code.
     *
     * Kept separate from {@see self::$localeFormats} rather than caching the whole row,
     * because the two are asked for by different screens — a board prints a hundred dates
     * and never asks which day a week opens on, and the calendar asks the opposite.
     *
     * @var array<string, ?int>
     */
    private static array $localeWeekStarts = [];

    /**
     * A date as a value: the installation's chosen format, with the month and weekday names
     * in the language being rendered.
     */
    public static function date(?DateTimeInterface $date, string $missing = self::MISSING): string
    {
        return self::using($date, self::pattern(), $missing);
    }

    /**
     * The same, with the time of day after it.
     *
     * The instant is printed as stored rather than converted, which is what every call site
     * this replaces already did. Timezone display is a separate decision and not one this
     * class should make silently on the way past.
     */
    public static function dateTime(?DateTimeInterface $date, string $missing = self::MISSING): string
    {
        return self::using($date, self::pattern().' '.self::TIME, $missing);
    }

    /**
     * A date in a pattern the caller names, rather than the one in force.
     *
     * The settings screen's format picker uses it to label each option with what that
     * option actually produces — the same code path, so the preview cannot drift from the
     * thing it is previewing.
     */
    public static function using(?DateTimeInterface $date, string $pattern, string $missing = self::MISSING): string
    {
        if (! $date instanceof DateTimeInterface) {
            return $missing;
        }

        // translatedFormat, not format: the second is PHP's own and answers in English
        // whatever the application locale is, which is the bug this class exists to close.
        return CarbonImmutable::instance($date)->translatedFormat(self::punctuate($pattern));
    }

    /**
     * The `date()` format string in force for the language and workspace being rendered.
     */
    public static function pattern(?Workspace $workspace = null): string
    {
        $workspace ??= app(CurrentWorkspace::class)->get();

        $candidates = [
            self::localeFormat(App::getLocale()),
            $workspace?->date_format,
            config('planvio.defaults.workspace.date_format'),
            self::FALLBACK_DATE,
        ];

        foreach ($candidates as $candidate) {
            $pattern = is_string($candidate) ? trim($candidate) : '';

            if ($pattern !== '') {
                return $pattern;
            }
        }

        return self::FALLBACK_DATE;
    }

    /**
     * The day a week opens on for the language and workspace being rendered.
     *
     * `0` is Sunday through `6` for Saturday, which is what `Carbon::startOfWeek()` takes
     * and what `workspaces.week_starts_on` stores.
     *
     * ## Why this is a rendering decision and not a data one
     *
     * A calendar, a timesheet and a timeline header are drawings: which column the week
     * opens in is a reading preference, and an Arabic reader who expects السبت first is
     * not disagreeing with their colleagues about any fact. So the language may override
     * the workspace here, and the same three screens will differ between two people
     * looking at the same workspace. That is correct — they are reading, not counting.
     *
     * `DateResolver` and the AI tools that lean on it deliberately do **not** come through
     * here. When somebody asks the assistant what is due *next week*, the answer is a set
     * of tasks, and a set of tasks cannot depend on the language the question was typed
     * in — two people in one workspace would get different lists from the same words, and
     * only one of them would be right. Those paths stay on `workspaces.week_starts_on`,
     * which is the workspace's single answer to "when does our week start".
     *
     * The precedence is {@see self::pattern()}'s, for the same reason: most specific first.
     */
    public static function weekStartsOn(?Workspace $workspace = null): int
    {
        $workspace ??= app(CurrentWorkspace::class)->get();

        $candidates = [
            self::localeWeekStart(App::getLocale()),
            $workspace?->week_starts_on,
            config('planvio.defaults.workspace.week_starts_on'),
            self::FALLBACK_WEEK_START,
        ];

        foreach ($candidates as $candidate) {
            // Sunday is 0, so a candidate is skipped only when it is absent or out of
            // range — never for being falsy. `?:` here would silently promote every
            // Sunday-first installation to Monday and look like it was working.
            if ($candidate === null || ! is_numeric($candidate)) {
                continue;
            }

            $day = (int) $candidate;

            if ($day >= 0 && $day <= 6) {
                return $day;
            }
        }

        return self::FALLBACK_WEEK_START;
    }

    /**
     * A whole number, grouped. Latin digits, whatever the language.
     */
    public static function number(int|float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, self::DECIMAL_POINT, self::THOUSANDS_SEPARATOR);
    }

    /**
     * A number with a fixed number of decimal places — an hour count, a percentage, a rate.
     */
    public static function decimal(int|float $value, int $decimals = 1): string
    {
        return self::number($value, $decimals);
    }

    /**
     * The two ends of a numeric range, ready to hand to a translated sentence.
     *
     * `:from–:to of :total` renders "1–10 of 10" in English and "10–1 من أصل 10" in Arabic,
     * because an en dash is a *neutral* character: between two numbers inside a right-to-left
     * paragraph the bidi algorithm resolves it to the paragraph direction and lays the two
     * figures out from the right, so the range reads backwards. (A hyphen-minus would
     * survive — it is a European separator and gets absorbed into the number run — which is
     * exactly why this is invisible until somebody looks at a translated page.)
     *
     * The fix is an isolate spanning the whole range. It cannot be `x-ui.bidi` here: the two
     * figures and the dash between them live inside one translated string, and markup cannot
     * reach between two placeholders. U+2066 LEFT-TO-RIGHT ISOLATE … U+2069 POP DIRECTIONAL
     * ISOLATE are the character-level spelling of the same thing (Unicode Annex #9), they
     * carry no width, and they leave the catalogue key alone — which matters, because every
     * translation of it already exists.
     *
     * @return array{from: string, to: string}
     */
    public static function range(int|string $from, int|string $to): array
    {
        return [
            'from' => self::ISOLATE_START.$from,
            'to' => $to.self::ISOLATE_END,
        ];
    }

    /**
     * Join phrases into one sentence with the comma the script actually uses.
     *
     * A list separator is punctuation, and punctuation is language rather than notation —
     * the same decision {@see self::punctuate()} makes inside a date format, applied to the
     * one other place Planvio builds a list in code instead of in a catalogue. Arabic sets
     * ، (U+060C), which the vendored Noto Sans Arabic covers; an ASCII comma between two
     * Arabic clauses is small, and the kind of small that tells a reader the language was
     * an afterthought.
     *
     * @param list<string> $items
     */
    public static function list(array $items): string
    {
        return implode(self::isArabicScript(App::getLocale()) ? '، ' : ', ', $items);
    }

    /**
     * Forget the looked-up locale overrides. For a test that writes one mid-run; nothing in
     * a request has any reason to call it.
     */
    public static function flush(): void
    {
        self::$localeFormats = [];
        self::$localeWeekStarts = [];
    }

    /**
     * The separators inside a format string, in the script being rendered.
     *
     * Two of the seven formats Planvio offers put a comma between the parts of a date, and
     * a comma is punctuation rather than notation: Arabic sets it as ، (U+060C), which the
     * vendored Noto Sans Arabic covers. An ASCII comma inside an Arabic date is the same
     * class of mistake as an English month name inside one — small, and the kind of small
     * that tells a reader the language was an afterthought.
     *
     * Only unescaped separators are touched. `\,` in a `date()` pattern is a literal comma
     * the author asked for, and stays one.
     *
     * Public because two notifications keep a pattern of their own rather than the
     * workspace's — see LOCALISATION.md §7, a named month is what a mail client three weeks
     * later can read — and a pattern that skips {@see self::pattern()} would otherwise skip
     * this too, which is how `16 سبتمبر 2026, 19:49` ended up in an Arabic invitation.
     */
    public static function punctuate(string $pattern): string
    {
        if (! str_contains($pattern, ',') || ! self::isArabicScript(App::getLocale())) {
            return $pattern;
        }

        return (string) preg_replace('/(?<!\\\\),/', '،', $pattern);
    }

    /**
     * Whether a BCP-47 code names a language written in the Arabic script.
     *
     * The language subtag is enough for what this decides. It is deliberately not a
     * direction check: Hebrew reads right to left and sets an ASCII comma.
     */
    private static function isArabicScript(string $code): bool
    {
        $language = strtolower(explode('-', str_replace('_', '-', $code))[0]);

        return in_array($language, ['ar', 'fa', 'ur', 'ps', 'sd', 'ug', 'ckb'], true);
    }

    /**
     * The per-language override, when this installation offers the language and set one.
     *
     * Swallows a database failure rather than propagating it, for the same reason
     * {@see Locale::enabledByCode()} does: the installer renders localised screens before
     * the table exists, and a date that falls back to ISO is recoverable where a fatal
     * error on the first screen of the wizard is not.
     */
    private static function localeFormat(string $code): ?string
    {
        if ($code === '') {
            return null;
        }

        if (array_key_exists($code, self::$localeFormats)) {
            return self::$localeFormats[$code];
        }

        try {
            $stored = Locale::query()->where('code', $code)->value('date_format');
        } catch (Throwable) {
            // No database, or no locales table: the installer renders localised screens
            // before either exists. Not remembered, so the first request after the tables
            // are there asks again.
            return null;
        }

        return self::$localeFormats[$code] = is_string($stored) && trim($stored) !== ''
            ? trim($stored)
            : null;
    }

    /**
     * The per-language week start, when this installation offers the language and set one.
     *
     * Out-of-range values are cached as `null` rather than clamped: a stored `9` is not a
     * near-miss for Saturday, it is a row that should not have been written, and answering
     * "no opinion" hands the decision back to the workspace where an administrator can
     * actually see it. The same swallowed-failure reasoning as {@see self::localeFormat()}
     * applies to the query itself.
     */
    private static function localeWeekStart(string $code): ?int
    {
        if ($code === '') {
            return null;
        }

        if (array_key_exists($code, self::$localeWeekStarts)) {
            return self::$localeWeekStarts[$code];
        }

        try {
            $stored = Locale::query()->where('code', $code)->value('first_day_of_week');
        } catch (Throwable) {
            return null;
        }

        if (! is_numeric($stored)) {
            return self::$localeWeekStarts[$code] = null;
        }

        $day = (int) $stored;

        return self::$localeWeekStarts[$code] = $day >= 0 && $day <= 6 ? $day : null;
    }
}
