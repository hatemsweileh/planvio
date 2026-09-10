<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * The `:name` substitutions inside a translatable line, and whether a translation kept them.
 *
 * ## Why this is a refusal rather than a warning
 *
 * `__('Due in :count days', ['count' => 3])` renders "Due in 3 days". A translator who writes
 * "Fällig in Tagen" has produced a sentence that reads perfectly and has silently lost the
 * number — nobody sees an error, and the German product simply stops telling anyone how many
 * days. Worse are the counted lines: `trans_choice` picks a plural form by `:count` and then
 * substitutes it, so a translation that dropped the token yields "1 task" and "5 task" that
 * both say neither number.
 *
 * There is no run-time signal for either. Laravel substitutes the placeholders it was given
 * and leaves the rest of the string alone, so a missing token is indistinguishable from a
 * sentence that never wanted one. The only moment the mistake is visible is the moment it is
 * written down, which is why the editor and the importer both refuse rather than warn.
 *
 * ## What counts as a placeholder
 *
 * A colon followed by a letter or underscore and then word characters, when the colon is not
 * itself preceded by a word character or another colon. That last exclusion keeps `Model::make`
 * and a `vendor::group` catalogue name out of the set; the first keeps clock times (`12:30`)
 * and URLs (`https://…`) out, since neither puts a letter straight after the colon it would
 * have to match.
 *
 * Names are compared case-insensitively because Laravel substitutes three casings of every
 * key it is handed — `:count`, `:Count` and `:COUNT` — so a translator who capitalised a
 * placeholder to start a sentence has still kept it.
 */
final class Placeholders
{
    private const PATTERN = '~(?<![A-Za-z0-9_:]):([A-Za-z_][A-Za-z0-9_]*)~';

    private function __construct() {}

    /**
     * Distinct placeholder names in $text, lower-cased, in the order they first appear.
     *
     * @return list<string>
     */
    public static function in(string $text): array
    {
        if (preg_match_all(self::PATTERN, $text, $matches) < 1) {
            return [];
        }

        $names = [];

        foreach ($matches[1] as $name) {
            $names[mb_strtolower($name)] = true;
        }

        return array_keys($names);
    }

    /**
     * Placeholders the source needs and the translation does not carry.
     *
     * @return list<string>
     */
    public static function missing(string $source, string $target): array
    {
        return array_values(array_diff(self::in($source), self::in($target)));
    }

    public static function preserved(string $source, string $target): bool
    {
        return self::missing($source, $target) === [];
    }

    /**
     * The refusal a person reads: which tokens are gone, and what happens if they stay gone.
     *
     * @param list<string> $missing
     */
    public static function refusal(string $key, array $missing): string
    {
        return __('The translation of ":key" is missing :placeholders. Laravel substitutes those at render time, so a line without them loses the value it was meant to show.', [
            'key' => self::excerpt($key),
            'placeholders' => implode(', ', array_map(static fn (string $name): string => ':'.$name, $missing)),
        ]);
    }

    /**
     * Keys are whole sentences; a refusal is for recognising the line, not for reading it.
     */
    public static function excerpt(string $key, int $length = 60): string
    {
        $key = preg_replace('~\s+~u', ' ', $key) ?? $key;

        return mb_strlen($key) > $length ? mb_substr($key, 0, $length - 1).'…' : $key;
    }
}
