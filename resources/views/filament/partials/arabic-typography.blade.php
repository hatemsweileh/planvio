{{--
    Arabic typography for the administration panel.

    The panel does not load resources/css/app.css — it has its own Tailwind build and serves
    its own Inter — so the three corrections written out at length in that file do not reach
    it, and neither does the Arabic face. Left alone, /admin sets Arabic in whatever the
    operating system happens to offer, at Latin leading, with the negative tracking Filament
    puts on every heading.

    The mechanism is the same one app.css uses and is documented there: redefine the theme
    variables Tailwind's own utilities read, on the root element, rather than out-specifying
    the utilities. Filament 5 is Tailwind 4 and reads exactly these names.

    Emitted only when the panel is actually rendering in Arabic, so a Latin installation
    pays nothing — not even the @font-face.

    The hook is `:lang(ar)` rather than `[dir='rtl']` for the reason app.css gives: direction
    and script are different facts, and Hebrew wants the mirroring without any of this.
--}}
<style>
    /*
       A repository asset rather than a build artefact, so it is on disk on a server where
       `npm run build` has never run — the same file the installer and the error page use.
    */
    @font-face {
        font-family: 'Noto Sans Arabic';
        font-style: normal;
        font-weight: 100 900;
        font-display: swap;
        src: url('{{ asset('fonts/noto-sans-arabic/noto-sans-arabic.woff2') }}') format('woff2');
        unicode-range: U+0600-06FF, U+0750-077F, U+08A0-08FF, U+FB50-FDFF, U+FE70-FEFF;
    }

    :root:lang(ar) {
        /* After Inter, so Latin in the panel still resolves to Inter first. */
        --font-family: 'Inter Variable', 'Noto Sans Arabic';

        /* 1. Leading. Arabic has no x-height to sit on; at 1.5 the descenders of one
              line meet the marks of the next. */
        --text-xs--line-height: 1.3rem;
        --text-sm--line-height: 1.5rem;
        --text-base--line-height: 1.7rem;
        --text-lg--line-height: 1.85rem;
        --text-xl--line-height: 2rem;
        --text-2xl--line-height: 2.25rem;
        --text-3xl--line-height: 2.6rem;
        line-height: 1.7;

        /* 2. Letter spacing. The script is joined, so tracking stretches the joins and
              pulls the word into pieces instead of opening it up. */
        --tracking-tighter: 0em;
        --tracking-tight: 0em;
        --tracking-normal: 0em;
        --tracking-wide: 0em;
        --tracking-wider: 0em;
        --tracking-widest: 0em;

        /* 3. Weight. Noto Sans Arabic puts more ink on the line than Inter at the same
              number, so half a step down matches the emphasis. */
        --font-weight-semibold: 550;
        --font-weight-bold: 650;
    }

    /*
       Arabic has no letter case, so upper-casing an Arabic label does nothing — but the
       label beside it may be a project key or a currency code somebody typed in Latin, and
       that one is shouted while its neighbours are not.
    */
    /*
       Planvio's own panel pages write their column headers as inline styles, because the
       panel does not load the product's Tailwind build — and an inline declaration outranks
       every selector here. Those are corrected where they are written, in
       resources/views/filament/pages/*.blade.php, rather than fought with `!important`.
    */
</style>
