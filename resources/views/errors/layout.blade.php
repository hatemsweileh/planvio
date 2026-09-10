{{--
    Deliberately self-contained: no compiled asset, no layout inheritance, no
    database read. An error page has to render when the rest of the application
    cannot, including before installation and when the Vite manifest is missing.
--}}
@php
    // Resolved once by SetLocale and shared with every view; see layouts/app.blade.php.
    $direction = ($textDirection ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') &middot; {{ config('planvio.brand.name', 'Planvio') }}</title>
    <link rel="icon" href="/img/brand/planvio-mark.svg" type="image/svg+xml">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f3f4f7;
            --panel: #ffffff;
            --line: #e4e7ec;
            --text: #2a2e37;
            --strong: #101319;
            --muted: #6e7581;
            --brand: #3f66b0;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #101319;
                --panel: #1b1f27;
                --line: #2a2e37;
                --text: #e4e7ec;
                --strong: #f9fafb;
                --muted: #9aa0ac;
                --brand: #7695d0;
            }
        }
        /*
           A repository asset, not a build artefact, so it is on disk even on a server where
           `npm run build` has never run — which is exactly the condition under which
           somebody is most likely to be looking at this page. Without it an Arabic error
           page renders in whatever face the machine happens to have.
        */
        @font-face {
            font-family: 'Noto Sans Arabic';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url('/fonts/noto-sans-arabic/noto-sans-arabic.woff2') format('woff2');
            unicode-range: U+0600-06FF, U+0750-077F, U+08A0-08FF, U+FB50-FDFF, U+FE70-FEFF;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 2rem 1.25rem;
            background: var(--bg);
            color: var(--text);
            font: 400 15px/1.6 'InterVariable', 'Noto Sans Arabic', ui-sans-serif, system-ui,
                  -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        /*
           Arabic typography, in the small. The reasoning is written out once in
           resources/css/app.css; this page carries its own copy of the three corrections
           because it cannot load that stylesheet.
        */
        body:lang(ar) { line-height: 1.8; }
        :lang(ar) .code,
        :lang(ar) h1 { letter-spacing: normal; text-transform: none; font-weight: 550; }
        .card {
            width: 100%;
            max-width: 30rem;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 1rem;
            padding: 2.5rem 2rem;
            text-align: center;
            box-shadow: 0 1px 3px rgb(16 19 25 / .06), 0 12px 40px -16px rgb(16 19 25 / .18);
        }
        .mark { width: 2.25rem; height: 2.75rem; color: var(--strong); margin-bottom: 1.75rem; }
        .code {
            display: inline-block;
            font-size: .6875rem;
            font-weight: 600;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--brand);
            background: color-mix(in oklab, var(--brand) 12%, transparent);
            padding: .25rem .55rem;
            border-radius: .375rem;
            margin-bottom: 1rem;
        }
        h1 {
            margin: 0 0 .625rem;
            font-size: 1.375rem;
            font-weight: 600;
            letter-spacing: -.014em;
            color: var(--strong);
        }
        p { margin: 0 auto; max-width: 24rem; color: var(--muted); font-size: .9375rem; }
        .actions { margin-top: 1.75rem; display: flex; gap: .625rem; justify-content: center; flex-wrap: wrap; }
        a.btn {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .5rem .95rem;
            border-radius: .5rem;
            font-size: .875rem;
            font-weight: 500;
            text-decoration: none;
            border: 1px solid transparent;
            transition: background-color .15s, border-color .15s;
        }
        a.primary { background: var(--brand); color: #fff; }
        a.primary:hover { filter: brightness(.94); }
        a.ghost { border-color: var(--line); color: var(--text); }
        a.ghost:hover { background: var(--bg); }
        .ref {
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--line);
            font-size: .75rem;
            color: var(--muted);
        }
        /*
           A path or a timestamp is an identifier, not prose. Inside an Arabic sentence the
           bidi algorithm resolves its trailing punctuation to the paragraph and prints it at
           the wrong end, so each run is isolated the way resources/css/app.css isolates code
           in the product.
        */
        code {
            font-family: ui-monospace, Menlo, Consolas, monospace;
            font-size: .75rem;
            unicode-bidi: isolate;
        }
    </style>
</head>
<body>
    <main class="card">
        <svg class="mark" viewBox="0 0 300.71 365.36" fill="none" aria-hidden="true">
            <path fill="currentColor" d="M15.83.16C36.35.14,184.75,0,192.78,0c.23,0,.45.02.68.05,80.78,11.42,128.84,94.6,97.66,170.72-18.27,44.6-59.03,71.55-106.91,73.95-3.64.18-27.31.36-28.57-.49-.27-.18-.5-.47-.7-.81-.71-1.19-.75-2.66-.23-3.95l44.56-111.86c.13-.33.23-.68.29-1.03,3.19-20.09-7.77-37.86-27.88-41.67-.3-.06-.6-.08-.91-.08,0,0-114.86,1.64-153.11.07-.83-.03-.28,0-.83-.07-6.3-.79-11.72-3.71-14.65-9.13-.93-1.73-1.38-3.68-1.38-5.65-.01-18.56-.16-38.4-.05-56.63.03-5.36,4.33-9.72,9.23-12,1.83-.85,3.83-1.25,5.85-1.25Z"/>
            <path fill="#3f66b0" d="M161.46,119.05c2.89,2.71,3.49,7.72,2.08,11.99l-58.32,156.6c-2.25,9.24-12.44,10.94-17.68,3.52-.23-.33-.39-.7-.49-1.08-6.08-23.24-12.09-46.52-18.99-69.51-.32-1.05-1.11-1.89-2.14-2.26-15.67-5.61-31.78-10.14-47.43-15.79-3.47-1.25-12.75-4.07-15.06-5.72-3.03-2.16-3.3-8.74-2.21-12.09,1.8-5.5,8.1-7.36,12.55-9.43,39.16-18.2,79.97-35.66,119.92-51.95,6.6-2.69,16.46-7.73,23.31-6.31.6.12,2.56.28,4.45,2.05Z"/>
            <path fill="currentColor" d="M47.27,235.01c3.11.08,4.56,3.89,5.24,6.42,5.46,20.42,9.89,44.05,13.97,64.94,2.3,11.76,7.29,29.04,3.78,40.21-2.48,7.9-8.85,14.24-16.48,17.46-2.1.88-4.36,1.29-6.63,1.3l-38.69.03c-2.55,0-5.05-1.15-6.46-3.28-.76-1.15-1.22-2.51-1.26-3.99,1.89-26.41-2.38-55.95,0-81.98,1.71-18.68,18.01-28.6,33.32-35.62,2.15-.98,11.93-5.51,13.2-5.47Z"/>
        </svg>

        <span class="code">@yield('code')</span>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>

        <div class="actions">
            @hasSection('actions')
                @yield('actions')
            @else
                <a class="btn primary" href="{{ url('/') }}">
                    {{ __('Back to :app', ['app' => config('planvio.brand.name', 'Planvio')]) }}
                </a>
            @endif
        </div>

        @hasSection('reference')
            <div class="ref">@yield('reference')</div>
        @endif
    </main>
</body>
</html>
