<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Keeping a value in the order it was written when it is set inside Arabic prose.
 *
 * `resources/views/components/ui/bidi.blade.php` is the markup form of this idea and is the
 * right one wherever a value has its own element. This class is the other half: the same
 * isolation as characters, for the places markup cannot reach — a value handed to `__()` as
 * a `:placeholder`, an `aria-label`, a `title`, a `wire:confirm` sentence, and a run buried
 * inside text somebody else wrote.
 *
 * ## What goes wrong without it
 *
 * Two mechanisms, and the second is the one nobody expects.
 *
 * A Latin-then-digit run whose separator is a neutral character splits: `/api/v1` inside an
 * Arabic sentence resolves its leading slash to the paragraph direction and renders
 * `api/v1/`.
 *
 * And an Arabic **letter** earlier in the same paragraph turns every following digit from a
 * European number into an *Arabic* number (rule W2), which takes the hyphens between them out
 * of the number and hands them to the paragraph — so `2026-09-02` in an Arabic sentence
 * renders `02-09-2026`. Every character is present, in an order nobody wrote. It is not fixed
 * by putting the sentence in an `ltr` container, because that rule looks at the preceding
 * letter rather than at the direction: the only thing that fixes it is isolating the run.
 *
 * ## Why characters rather than markup
 *
 * U+2066..U+2069 are what the isolation is actually specified in — `unicode-bidi: isolate` is
 * defined in terms of them — so they behave identically, travel through `e()` untouched,
 * survive into an attribute value, and are invisible when the text is read aloud or copied.
 * A `<span>` does none of that inside `aria-label`.
 */
final class Bidi
{
    /** Left-to-right isolate: the run inside reads left to right whatever surrounds it. */
    private const LRI = "\u{2066}";

    /** First-strong isolate: the run picks its own direction, like `dir="auto"`. */
    private const FSI = "\u{2068}";

    /** Pop directional isolate: closes either of the two above. */
    private const PDI = "\u{2069}";

    /**
     * A run that is read left to right: an identifier, a path, a timestamp, a version, a
     * task key, a signed or suffixed number.
     */
    public static function ltr(string $value): string
    {
        return $value === '' ? '' : self::LRI.$value.self::PDI;
    }

    /**
     * A run whose direction is its own content's: a name, a title, a sentence somebody typed
     * in a language this installation does not know.
     */
    public static function auto(string $value): string
    {
        return $value === '' ? '' : self::FSI.$value.self::PDI;
    }

    /**
     * Isolate the numeric runs *inside* text this product did not write.
     *
     * A tool argument, a model's own summary of what it did, a note somebody typed: prose in
     * one language with dates, times, versions and ratios embedded in it. The prose is left
     * exactly as it is — only `2026-09-02`, `14:30` and `1.2/3` style runs are wrapped, and
     * only when they carry a separator between digits, which is the shape that splits.
     */
    public static function numbers(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $isolated = preg_replace_callback(
            '/\d+(?:[.:\/-]\d+)+/u',
            static fn (array $m): string => self::ltr($m[0]),
            $value,
        );

        return is_string($isolated) ? $isolated : $value;
    }
}
