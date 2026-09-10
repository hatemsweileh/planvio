{{--
    The signed-out shell: sign in, register, password reset, email verification, the
    two-factor challenge and enrolment, and the first-workspace screen.

    Deliberately independent of layouts/app.blade.php. That layout assumes a bound
    workspace, a sidebar and a command palette; none of those exist on these screens, and
    somebody who cannot sign in should not be looking at the navigation of a product they
    are not inside yet.

    Sections: title · heading · subheading · content · footer. Each also accepts a variable
    of the same name, so a Livewire page component can drive the same layout through
    `view()->extends('layouts.guest')->section('content')->layoutData([...])`.
--}}
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
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#3F66B0">

    @php($branding = app(\App\Support\Branding::class))

    <title>@yield('title', $title ?? '') &middot; {{ $branding->name() }}</title>

    <link rel="icon" href="{{ $branding->favicon() }}" type="image/svg+xml">

    {{--
        Theme is applied before first paint. Doing it in the bundle instead would flash a
        white card at every dark-mode user on the one screen they see most often.
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

    @stack('head')
</head>
<body class="h-full antialiased bg-[var(--surface-canvas)]">

<div class="flex min-h-full flex-col items-center justify-center px-4 py-10 sm:px-6">

    <a href="{{ url('/') }}"
       class="mb-7 inline-flex items-center gap-2.5 text-[var(--text-strong)]"
       aria-label="{{ $branding->name() }}">
        <x-ui.brand-mark class="h-8 w-auto" />
        <span class="text-lg font-semibold tracking-tight">{{ $branding->name() }}</span>
    </a>

    <main class="w-full {{ $width ?? 'max-w-sm' }}">
        <div class="rounded-xl border border-[var(--line-subtle)] bg-[var(--surface-panel)] p-6 shadow-panel sm:p-7">
            @hasSection('heading')
                <h1 class="text-base font-semibold tracking-tight text-[var(--text-strong)]">@yield('heading')</h1>
            @elseif (filled($heading ?? null))
                <h1 class="text-base font-semibold tracking-tight text-[var(--text-strong)]">{{ $heading }}</h1>
            @endif

            @hasSection('subheading')
                <p class="mt-1 text-pretty text-sm text-[var(--text-muted)]">@yield('subheading')</p>
            @elseif (filled($subheading ?? null))
                <p class="mt-1 text-pretty text-sm text-[var(--text-muted)]">{{ $subheading }}</p>
            @endif

            @if (session('status'))
                <p class="mt-4 rounded-md border border-[var(--line-subtle)] bg-[var(--surface-sunken)]
                          px-3 py-2 text-sm text-[var(--text-DEFAULT)]"
                   role="status">
                    {{ session('status') }}
                </p>
            @endif

            <div class="mt-5">
                @yield('content')
            </div>
        </div>

        @hasSection('footer')
            <p class="mt-5 text-center text-sm text-[var(--text-muted)]">@yield('footer')</p>
        @endif

        {{-- The release, quietly. Support requests are answered far faster with it. --}}
        <p class="mt-6 text-center text-2xs text-[var(--text-subtle)]">
            {{ $branding->name() }} <x-ui.bidi>{{ \App\Support\Version::app() }}</x-ui.bidi>
        </p>
    </main>
</div>

@stack('scripts')
</body>
</html>
