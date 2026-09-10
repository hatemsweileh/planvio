@props(['dir' => 'ltr'])

{{--
    A run of text that must not take part in the surrounding paragraph's bidi ordering.

    Arabic reorders. Drop "WEB-142" into an Arabic sentence and the algorithm treats the
    hyphen as neutral, resolves it to the paragraph direction and shows you "142-WEB". The
    same happens to "-12%", to "14:30 → 16:00", to a version string and to a file path:
    the characters are all still there, in an order nobody wrote and nobody can act on.

    So identifiers, timestamps, durations, code and signed or suffixed numbers get wrapped
    in this. `dir` is an attribute rather than a class because it is what the isolation is
    actually keyed on — it also travels to assistive technology and to the clipboard — and
    `unicode-bidi: isolate` is what stops the run from reordering its neighbours in turn.

    Pass `dir="auto"` for a value whose direction depends on its content, such as a name
    typed by whoever entered it, where the point is isolation rather than Latin ordering.
--}}
<span dir="{{ $dir }}" {{ $attributes->class('bidi-isolate') }}>{{ $slot }}</span>
