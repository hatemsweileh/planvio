@php
    // Resolved once by SetLocale and shared with every view; see layouts/app.blade.php.
    $direction = ($textDirection ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ $direction }}"
      class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    @php
        $branding = app(\App\Support\Branding::class);
        $workspace ??= app(\App\Support\CurrentWorkspace::class)->get();
    @endphp

    <title>{{ $title ?? '' }} {{ ($title ?? false) ? '·' : '' }} {{ $branding->name() }}</title>

    <link rel="icon" href="{{ $branding->favicon() }}" type="image/svg+xml">

    {{--
        A document, not an application screen.

        The product shell is deliberately absent: this page exists to be read, printed and
        handed to somebody who does not use Planvio, and a sidebar, a command palette and a
        notification bell are all noise on paper. `@media print` in app.css already strips
        `nav`, `aside` and `.print:hidden`; what is left here is the report itself.
    --}}
    <script>
        (function () {
            try {
                var pref = localStorage.getItem('planvio.theme') || 'system';
                var dark = pref === 'dark' ||
                    (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @if ($accent = $workspace?->accent_color)
        <style>:root { --accent: {{ $accent }}; --accent-hover: color-mix(in oklab, {{ $accent }} 85%, black); }</style>
    @endif

    <style>
        @page { margin: 14mm; }
        @media print {
            html, body { background: #fff !important; }
            .print-sheet { max-width: none !important; padding: 0 !important; }
            .print-avoid-break { break-inside: avoid; }
            a[href]::after { content: ''; }
        }
    </style>

    @stack('head')
</head>
<body class="min-h-full antialiased">
    {{ $slot }}

    @include('app.partials.toasts')

    @stack('scripts')
</body>
</html>
