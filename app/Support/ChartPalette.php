<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * The only colours a chart may draw with.
 *
 * Every value is a design token declared in `resources/css/app.css`, never a literal. That
 * is what makes a chart follow the theme: the same series is a readable mid-blue on paper,
 * on the light canvas and on the dark one, because the token — not the chart — decides what
 * "brand" resolves to. A hard-coded `#3F66B0` in an SVG would be invisible in dark mode and
 * would ignore a workspace that swapped its accent.
 *
 * The names deliberately match the ones the enums return from `color()` — `Priority::High`
 * says `orange`, `ProjectHealth::AtRisk` says `amber` — so a chart of statuses and a row of
 * `<x-ui.badge>`s beside it are the same colour without either side translating.
 *
 * Only tokens the project declares itself are used. Tailwind emits the theme variables
 * defined in its own `@theme` block unconditionally, while the framework's default palette
 * is tree-shaken by usage; referencing `var(--color-blue-500)` from an inline attribute the
 * scanner never reads would therefore resolve to nothing at all.
 */
final class ChartPalette
{
    /**
     * @var array<string, string>
     */
    private const TOKENS = [
        'brand' => 'var(--color-brand-500)',
        'blue' => 'var(--color-brand-400)',
        'indigo' => 'var(--color-brand-700)',
        'green' => 'var(--color-positive-500)',
        'teal' => 'var(--color-positive-700)',
        'amber' => 'var(--color-caution-500)',
        'orange' => 'var(--color-caution-600)',
        'red' => 'var(--color-critical-500)',
        'purple' => 'var(--color-accent-500)',
        'pink' => 'var(--color-accent-600)',
        'gray' => 'var(--color-ink-400)',
        'muted' => 'var(--color-ink-300)',
        'accent' => 'var(--accent)',
    ];

    /**
     * Categorical order. Adjacent entries are far enough apart in hue and in lightness to
     * stay distinguishable side by side, and — because several are, in practice, the only
     * distinction a printed bar chart carries — in greyscale too.
     *
     * @var list<string>
     */
    private const SEQUENCE = [
        'brand', 'purple', 'green', 'amber', 'red', 'blue', 'teal', 'pink', 'indigo', 'gray',
    ];

    /**
     * Resolve a palette name, or a literal CSS value a caller already resolved.
     *
     * A value that already looks like CSS — `var(...)`, `currentColor` — passes through, so
     * a caller holding a token can hand it over without a second lookup. Anything unknown
     * falls back to the neutral rather than to nothing: a bar with no fill is a bar nobody
     * can see.
     */
    public static function color(?string $name): string
    {
        if ($name === null || $name === '') {
            return self::TOKENS['gray'];
        }

        if (str_starts_with($name, 'var(') || str_starts_with($name, 'currentColor')) {
            return $name;
        }

        return self::TOKENS[$name] ?? self::TOKENS['gray'];
    }

    /**
     * The nth categorical colour, wrapping round.
     */
    public static function series(int $index): string
    {
        $names = self::SEQUENCE;

        return self::color($names[abs($index) % count($names)]);
    }

    /**
     * The nth categorical colour's *name*, for callers that pass names on to a badge.
     */
    public static function seriesName(int $index): string
    {
        $names = self::SEQUENCE;

        return $names[abs($index) % count($names)];
    }

    /**
     * @return list<string> $count colours, in categorical order
     */
    public static function sequence(int $count): array
    {
        $colors = [];

        for ($index = 0; $index < max(0, $count); $index++) {
            $colors[] = self::series($index);
        }

        return $colors;
    }

    /**
     * A stable colour for an arbitrary key — a project id, a person's name.
     *
     * Deterministic, so the same project is the same colour on the calendar, on the
     * timeline and in every report, on every machine and after every deploy. That
     * consistency is the whole value of colour-coding; a random assignment per render
     * would be decoration rather than information.
     */
    public static function forKey(string $key): string
    {
        return self::color(self::nameForKey($key));
    }

    public static function nameForKey(string $key): string
    {
        $names = self::SEQUENCE;

        return $names[abs(crc32($key)) % count($names)];
    }

    /**
     * What a colour picker calls this token in the reader's language.
     *
     * A swatch is labelled with the only word on it, so `teal` in an otherwise Arabic form
     * is an untranslated string on screen rather than an identifier — unlike the token in a
     * `<code>` block or in a template's JSON, which stays as it is. The key is assembled at
     * run time from the token, so the lines live in a group file (see `lang/README.md`).
     *
     * An unknown token is returned as it stands: a workspace that stored a hex colour or a
     * name this build does not know still gets something legible on the swatch.
     */
    public static function label(?string $name): string
    {
        $name = (string) $name;

        if ($name === '') {
            return '';
        }

        $line = 'enums.color.'.$name;

        return Lang::has($line) ? (string) __($line) : $name;
    }
}
