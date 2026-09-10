{{--
    The installer shell.

    Self-contained by necessity, the way resources/views/errors/layout.blade.php is. This
    layout has to render on a server where `npm run build` has never been run, where
    `public/build` was left out of an upload, and — on the requirements screen — where
    `storage/framework/views` is not even writable. So there is no @vite, no Tailwind class
    and no dependency on anything outside this file except Livewire's own endpoint.

    The token names below are the ones from resources/css/app.css, with the same values, so
    this screen and the product it installs are visibly one system rather than two. The
    literal hex values appear here for the same reason they appear in app.css: this is the
    file that *defines* the tokens. Nothing further down uses a colour any other way.
--}}
@php
    // Resolved once by SetLocale and shared with every view; see layouts/app.blade.php.
    // Before `migrate` has run SetLocale resolves it from Locale::SHIPPED and the wizard's
    // own session preference, so this screen is right to left in Arabic like every other.
    $direction = ($textDirection ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';

    // What the picker below offers: the catalogues inside this release, which is also
    // exactly what the first seed will write into `locales`.
    $installerLanguages = \App\Models\Locale::shipped();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#3F66B0">
    <title>@yield('title', $title ?? __('Install')) &middot; {{ config('planvio.brand.name', 'Planvio') }}</title>
    <link rel="icon" href="/img/brand/planvio-mark.svg" type="image/svg+xml">
    <style>
        :root {
            color-scheme: light;

            --surface-canvas: #f3f4f7;
            --surface-panel: #ffffff;
            --surface-sunken: #f9fafb;
            --surface-hover: #f3f4f7;

            --line-subtle: #e4e7ec;
            --line: #ced2da;
            --line-strong: #9aa0ac;

            --text-strong: #101319;
            --text: #2a2e37;
            --text-muted: #6e7581;
            --text-subtle: #9aa0ac;

            --accent: #3f66b0;
            --accent-hover: #315290;
            --accent-soft: #f5f7fd;
            --accent-soft-text: #315290;

            --positive: #059669;
            --positive-soft: #ecfdf5;
            --caution: #b45309;
            --caution-soft: #fffbeb;
            --critical: #dc2626;
            --critical-soft: #fef2f2;

            --radius-sm: .375rem;
            --radius: .5rem;
            --radius-lg: .75rem;
            --radius-xl: 1rem;

            --shadow-panel: 0 1px 2px 0 rgb(16 19 25 / .04), 0 1px 3px 0 rgb(16 19 25 / .06);
            --shadow-raised: 0 4px 12px -2px rgb(16 19 25 / .08), 0 2px 4px -2px rgb(16 19 25 / .04);
            --ease-snap: cubic-bezier(.32, .72, 0, 1);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                color-scheme: dark;

                --surface-canvas: #101319;
                --surface-panel: #1b1f27;
                --surface-sunken: #0b0d12;
                --surface-hover: #2a2e37;

                --line-subtle: #262b34;
                --line: #2a2e37;
                --line-strong: #3d424d;

                --text-strong: #f9fafb;
                --text: #e4e7ec;
                --text-muted: #9aa0ac;
                --text-subtle: #6e7581;

                --accent: #7695d0;
                --accent-hover: #a6bbe3;
                --accent-soft: #1e2735;
                --accent-soft-text: #cbd8f0;

                --positive: #10b981;
                --positive-soft: #052e21;
                --caution: #f59e0b;
                --caution-soft: #3a2306;
                --critical: #ef4444;
                --critical-soft: #3f1010;
            }
        }

        /*
           The one file this layout is allowed to reach for. It is a repository asset, not a
           build artefact, so it is there on a server where `npm run build` has never run —
           and without it an Arabic installer renders in whatever face the machine happens
           to have, which on a fresh Windows Server is not a pleasant surprise.
        */
        @font-face {
            font-family: 'Noto Sans Arabic';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url('/fonts/noto-sans-arabic/noto-sans-arabic.woff2') format('woff2');
            unicode-range: U+0600-06FF, U+0750-077F, U+08A0-08FF, U+FB50-FDFF, U+FE70-FEFF;
        }

        *, *::before, *::after { box-sizing: border-box; }

        html { -webkit-text-size-adjust: 100%; }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--surface-canvas);
            color: var(--text);
            font: 400 14px/1.55 'InterVariable', 'Noto Sans Arabic', ui-sans-serif, system-ui,
                  -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        h1, h2, h3, p, ul, ol, dl, dd, figure { margin: 0; }
        ul, ol { padding: 0; list-style: none; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        code, kbd { font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace; font-size: .8125rem; }

        /*
           Arabic typography, in the small. The reasoning is written out once in
           resources/css/app.css; this page cannot use that stylesheet, because it has to
           render before `npm run build` has ever been run on the server, so the same three
           corrections are repeated here rather than shared.

           Leading, because Arabic has no x-height to sit on and its ascenders, descenders
           and marks all run past the band Latin occupies. Letter spacing, because the
           script is joined and tracking a word out stretches the joins instead of opening
           the word. Weight, because Noto Sans Arabic puts more ink on the line than Inter
           does at the same number.
        */
        body:lang(ar) { line-height: 1.75; }
        :lang(ar) .eyebrow,
        :lang(ar) .group-title,
        :lang(ar) h1 { letter-spacing: normal; text-transform: none; font-weight: 550; }

        /* ---- shell ------------------------------------------------------ */

        .shell {
            width: 100%;
            max-width: 62rem;
            margin: 0 auto;
            padding: 2.5rem 1.25rem 4rem;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: .625rem;
            margin-bottom: 1.75rem;
            color: var(--text-strong);
        }
        .brand svg { width: 1.375rem; height: auto; }
        .brand-name { font-size: 1rem; font-weight: 600; letter-spacing: -.015em; }
        .brand-end { margin-inline-start: auto; display: flex; align-items: center; gap: .75rem; }
        .brand-version {
            font-size: .6875rem;
            color: var(--text-subtle);
            font-variant-numeric: tabular-nums;
        }

        /*
           The language of the wizard, before there is a database to hold a preference in.
           Buttons rather than a <select> that submits on change: this is the control
           somebody uses when they cannot read the screen, and it has to work with no
           JavaScript at all — the same standard the rest of this file is held to.
        */
        .lang { display: flex; align-items: center; gap: .125rem; }
        .lang-btn {
            appearance: none;
            border: 0;
            background: transparent;
            font: inherit;
            font-size: .75rem;
            line-height: 1.4;
            color: var(--text-muted);
            padding: .1875rem .4375rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
        }
        .lang-btn:hover { background: var(--surface-hover); color: var(--text); }
        .lang-btn.is-current {
            background: var(--surface-panel);
            color: var(--text-strong);
            box-shadow: var(--shadow-panel);
            cursor: default;
        }
        .lang-btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

        .frame { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
        @media (min-width: 62rem) {
            .frame { grid-template-columns: 13.5rem 1fr; gap: 2.5rem; align-items: start; }
        }

        /* ---- step rail --------------------------------------------------- */

        .rail { display: flex; gap: .25rem; overflow-x: auto; padding-bottom: .25rem; scrollbar-width: none; }
        .rail::-webkit-scrollbar { display: none; }
        @media (min-width: 62rem) {
            .rail { display: block; overflow: visible; position: sticky; top: 2.5rem; }
        }

        .rail-item {
            display: flex;
            align-items: center;
            gap: .5rem;
            padding: .3125rem .5rem;
            border-radius: var(--radius-sm);
            font-size: .8125rem;
            color: var(--text-muted);
            white-space: nowrap;
        }
        .rail-item + .rail-item { margin-top: .0625rem; }
        .rail-item.is-current { color: var(--text-strong); font-weight: 550; background: var(--surface-panel); box-shadow: var(--shadow-panel); }
        .rail-item.is-done { color: var(--text); }

        .rail-dot {
            display: grid;
            place-items: center;
            width: 1.125rem;
            height: 1.125rem;
            flex: none;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: var(--surface-panel);
            font-size: .625rem;
            font-variant-numeric: tabular-nums;
            color: var(--text-subtle);
        }
        .rail-item.is-current .rail-dot { border-color: var(--accent); background: var(--accent); color: #fff; }
        .rail-item.is-done .rail-dot { border-color: var(--accent); background: var(--accent-soft); color: var(--accent-soft-text); }

        /* ---- panel ------------------------------------------------------- */

        .panel {
            background: var(--surface-panel);
            border: 1px solid var(--line-subtle);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-panel);
        }
        .panel-head { padding: 1.5rem 1.5rem 0; }
        .panel-body { padding: 1.5rem; }
        .panel-foot {
            display: flex;
            align-items: center;
            gap: .625rem;
            flex-wrap: wrap;
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--line-subtle);
            background: var(--surface-sunken);
            border-radius: 0 0 var(--radius-xl) var(--radius-xl);
        }
        .panel-foot .spacer { margin-inline-start: auto; }

        .eyebrow {
            font-size: .6875rem;
            font-weight: 600;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--text-subtle);
        }
        h1 { margin: .375rem 0 0; font-size: 1.25rem; font-weight: 600; letter-spacing: -.02em; color: var(--text-strong); }
        .lede { margin-top: .5rem; max-width: 44rem; color: var(--text-muted); text-wrap: pretty; }

        /* ---- forms ------------------------------------------------------- */

        .grid { display: grid; gap: 1rem; }
        @media (min-width: 34rem) {
            .grid-2 { grid-template-columns: 1fr 1fr; }
            .grid-3 { grid-template-columns: 1fr 1fr 1fr; }
            .span-2 { grid-column: span 2; }
        }

        .field { display: block; }
        .label { display: block; margin-bottom: .375rem; font-size: .75rem; font-weight: 550; color: var(--text); }
        .label .req { color: var(--critical); }

        .input, .select {
            display: block;
            width: 100%;
            height: 2.375rem;
            padding: 0 .75rem;
            font: inherit;
            font-size: .875rem;
            color: var(--text-strong);
            background: var(--surface-panel);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: 0 1px 1px rgb(16 19 25 / .03);
            transition: border-color .12s, box-shadow .12s;
        }
        .select {
            appearance: none;
            padding-inline-end: 2rem;
            background-image: linear-gradient(45deg, transparent 50%, var(--text-subtle) 50%),
                              linear-gradient(135deg, var(--text-subtle) 50%, transparent 50%);
            background-position: right 1rem center, right .6875rem center;
            background-size: .3125rem .3125rem, .3125rem .3125rem;
            background-repeat: no-repeat;
        }
        /*
           The chevron is two gradient halves sitting side by side, so moving it to the other
           edge has to keep them in the same left-to-right order — the offsets swap, not just
           the edge keyword. Listed the other way round the halves trade places and the arrow
           comes out as two notches.
        */
        [dir="rtl"] .select { background-position: left .6875rem center, left 1rem center; }
        .input::placeholder { color: var(--text-subtle); }
        .input:focus, .select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px color-mix(in oklab, var(--accent) 22%, transparent);
        }
        .input.is-invalid, .select.is-invalid { border-color: var(--critical); }
        .input:disabled, .select:disabled { opacity: .55; cursor: not-allowed; }

        .hint { margin-top: .375rem; font-size: .75rem; color: var(--text-muted); }
        .error { margin-top: .375rem; font-size: .75rem; color: var(--critical); }

        .check { display: flex; align-items: flex-start; gap: .625rem; cursor: pointer; }
        .check input { width: 1rem; height: 1rem; margin: .1875rem 0 0; accent-color: var(--accent); flex: none; }
        .check-title { display: block; font-size: .875rem; color: var(--text-strong); font-weight: 550; }
        .check-note { display: block; font-size: .75rem; color: var(--text-muted); }

        /* ---- buttons ----------------------------------------------------- */

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .4375rem;
            height: 2.25rem;
            padding: 0 .875rem;
            font: inherit;
            font-size: .875rem;
            font-weight: 550;
            white-space: nowrap;
            border: 1px solid transparent;
            border-radius: var(--radius);
            cursor: pointer;
            transition: background-color .12s, border-color .12s, color .12s;
        }
        .btn:hover { text-decoration: none; }
        .btn:disabled, .btn[aria-disabled="true"] { opacity: .5; pointer-events: none; }
        .btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: var(--accent-hover); }
        .btn-secondary { background: var(--surface-panel); color: var(--text); border-color: var(--line); }
        .btn-secondary:hover { background: var(--surface-hover); border-color: var(--line-strong); }
        .btn-ghost { background: transparent; color: var(--text-muted); }
        .btn-ghost:hover { background: var(--surface-hover); color: var(--text); }

        /* ---- status ------------------------------------------------------ */

        .note {
            padding: .75rem .875rem;
            border: 1px solid var(--line-subtle);
            border-radius: var(--radius);
            background: var(--surface-sunken);
            font-size: .8125rem;
            color: var(--text);
        }
        .note strong { color: var(--text-strong); font-weight: 600; }
        .note p + p { margin-top: .375rem; }
        .note.is-pass { border-color: color-mix(in oklab, var(--positive) 35%, transparent); background: var(--positive-soft); }
        .note.is-warn { border-color: color-mix(in oklab, var(--caution) 35%, transparent); background: var(--caution-soft); }
        .note.is-fail { border-color: color-mix(in oklab, var(--critical) 35%, transparent); background: var(--critical-soft); }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: .3125rem;
            height: 1.375rem;
            padding: 0 .5rem;
            border-radius: var(--radius-sm);
            font-size: .6875rem;
            font-weight: 600;
            background: var(--surface-hover);
            color: var(--text-muted);
        }
        .pill.is-pass { background: var(--positive-soft); color: var(--positive); }
        .pill.is-warn { background: var(--caution-soft); color: var(--caution); }
        .pill.is-fail { background: var(--critical-soft); color: var(--critical); }

        /* ---- requirement + progress lists -------------------------------- */

        .group + .group { margin-top: 1.5rem; }
        .group-title {
            font-size: .6875rem;
            font-weight: 600;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--text-subtle);
            margin-bottom: .5rem;
        }

        .rows { border: 1px solid var(--line-subtle); border-radius: var(--radius-lg); overflow: hidden; }
        .row { display: flex; align-items: center; gap: .75rem; padding: .5rem .875rem; background: var(--surface-panel); }
        .row + .row { border-top: 1px solid var(--line-subtle); }
        .row-name { font-size: .8125rem; color: var(--text-strong); font-weight: 500; }
        .row-detail { margin-inline-start: auto; font-size: .75rem; color: var(--text-muted); text-align: end; }
        .row-remedy {
            /* The start inset lines the remedy up under the row's status mark. */
            padding: .625rem .875rem .75rem;
            padding-inline-start: 2.75rem;
            background: var(--surface-sunken);
            border-top: 1px dashed var(--line-subtle);
            font-size: .75rem;
            color: var(--text-muted);
            text-wrap: pretty;
        }

        .mark {
            display: grid;
            place-items: center;
            width: 1.25rem;
            height: 1.25rem;
            flex: none;
            border-radius: 999px;
            font-size: .6875rem;
            font-weight: 700;
            line-height: 1;
        }
        .mark.is-pass { background: var(--positive-soft); color: var(--positive); }
        .mark.is-warn { background: var(--caution-soft); color: var(--caution); }
        .mark.is-fail { background: var(--critical-soft); color: var(--critical); }

        .bar { height: .375rem; border-radius: 999px; background: var(--surface-hover); overflow: hidden; }
        .bar > span { display: block; height: 100%; border-radius: 999px; background: var(--accent); transition: width .35s var(--ease-snap); }

        /* Inherits the colour of whatever it sits in, so it reads on a solid button too. */
        .spin {
            width: .875rem; height: .875rem; flex: none;
            border: 2px solid color-mix(in oklab, currentColor 25%, transparent);
            border-top-color: currentColor;
            border-radius: 999px;
            animation: spin .6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) {
            .spin { animation-duration: 2s; }
            .bar > span { transition: none; }
        }

        /* ---- key/value summary ------------------------------------------- */

        .kv { display: grid; grid-template-columns: minmax(7rem, auto) 1fr; gap: .375rem .875rem; font-size: .8125rem; }
        .kv dt { color: var(--text-muted); }
        .kv dd { margin: 0; color: var(--text-strong); overflow-wrap: anywhere; }

        .divider { height: 1px; background: var(--line-subtle); margin: 1.25rem 0; }
        .stack > * + * { margin-top: 1rem; }
        .stack-sm > * + * { margin-top: .625rem; }

        .foot-note { margin-top: 1.25rem; text-align: center; font-size: .6875rem; color: var(--text-subtle); }

        /*
           Livewire ships this rule in its own stylesheet, and this file does not want to
           depend on that arriving: the alternative is a spinner sitting inside every button
           for as long as it takes /livewire/livewire.css to load.
        */
        [wire\:loading] { display: none; }

        .sr-only {
            position: absolute;
            width: 1px; height: 1px;
            padding: 0; margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        [hidden] { display: none !important; }
    </style>
    @livewireStyles
    @stack('head')
</head>
<body>
<div class="shell">
    <div class="brand">
        <svg viewBox="0 0 300.71 365.36" fill="none" aria-hidden="true">
            <g fill="currentColor">
                <path d="M15.83.16C36.35.14,184.75,0,192.78,0c.23,0,.45.02.68.05,80.78,11.42,128.84,94.6,97.66,170.72-18.27,44.6-59.03,71.55-106.91,73.95-3.64.18-27.31.36-28.57-.49-.27-.18-.5-.47-.7-.81-.71-1.19-.75-2.66-.23-3.95l44.56-111.86c.13-.33.23-.68.29-1.03,3.19-20.09-7.77-37.86-27.88-41.67-.3-.06-.6-.08-.91-.08,0,0-114.86,1.64-153.11.07-.83-.03-.28,0-.83-.07-6.3-.79-11.72-3.71-14.65-9.13-.93-1.73-1.38-3.68-1.38-5.65-.01-18.56-.16-38.4-.05-56.63.03-5.36,4.33-9.72,9.23-12,1.83-.85,3.83-1.25,5.85-1.25Z"/>
                <path d="M47.27,235.01c3.11.08,4.56,3.89,5.24,6.42,5.46,20.42,9.89,44.05,13.97,64.94,2.3,11.76,7.29,29.04,3.78,40.21-2.48,7.9-8.85,14.24-16.48,17.46-2.1.88-4.36,1.29-6.63,1.3l-38.69.03c-2.55,0-5.05-1.15-6.46-3.28-.76-1.15-1.22-2.51-1.26-3.99,1.89-26.41-2.38-55.95,0-81.98,1.71-18.68,18.01-28.6,33.32-35.62,2.15-.98,11.93-5.51,13.2-5.47Z"/>
            </g>
            <path fill="var(--accent)" d="M161.46,119.05c2.89,2.71,3.49,7.72,2.08,11.99l-58.32,156.6c-2.25,9.24-12.44,10.94-17.68,3.52-.23-.33-.39-.7-.49-1.08-6.08-23.24-12.09-46.52-18.99-69.51-.32-1.05-1.11-1.89-2.14-2.26-15.67-5.61-31.78-10.14-47.43-15.79-3.47-1.25-12.75-4.07-15.06-5.72-3.03-2.16-3.3-8.74-2.21-12.09,1.8-5.5,8.1-7.36,12.55-9.43,39.16-18.2,79.97-35.66,119.92-51.95,6.6-2.69,16.46-7.73,23.31-6.31.6.12,2.56.28,4.45,2.05Z"/>
        </svg>
        <span class="brand-name">{{ config('planvio.brand.name', 'Planvio') }}</span>

        <div class="brand-end">
            {{--
                Offered on every step, not only the first: the wizard is nine screens long,
                and a language you can only choose before you have read anything is a
                language you choose by accident. Hidden once the lock exists, because the
                finish screen resolves its language from the `locales` table like the rest
                of the product, and this form posts to a route behind `not-installed`.
            --}}
            @if (! \App\Http\Middleware\EnsureInstalled::isInstalled() && count($installerLanguages) > 1)
                <form class="lang" method="POST" action="{{ route('install.language') }}"
                      role="group" aria-label="{{ __('Language') }}">
                    @csrf
                    @foreach ($installerLanguages as $code => $language)
                        @php($isCurrent = $code === app()->getLocale())
                        <button type="submit" name="locale" value="{{ $code }}"
                                lang="{{ $code }}" dir="{{ $language->isRtl() ? 'rtl' : 'ltr' }}"
                                class="lang-btn {{ $isCurrent ? 'is-current' : '' }}"
                                @if ($isCurrent) aria-current="true" @endif>
                            {{ $language->native_name }}
                        </button>
                    @endforeach
                </form>
            @endif

            <span class="brand-version" dir="ltr">{{ \App\Support\Version::app() }}</span>
        </div>
    </div>

    <div class="frame">
        @include('installer.partials.rail', ['current' => $step ?? null])

        <main>
            @yield('content')
            <p class="foot-note">{{ __('Planvio is self-hosted. Nothing on these screens is sent anywhere but your own server.') }}</p>
        </main>
    </div>
</div>
@livewireScripts
@stack('scripts')
</body>
</html>
