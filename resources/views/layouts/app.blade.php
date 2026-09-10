@php
    /*
       SetLocale resolves the writing direction for the request's locale once and shares it
       with every view, so the list of right-to-left languages lives in one place instead of
       being restated in each shell. Narrowed to the two values a `dir` attribute may hold,
       because this also covers a view rendered outside the middleware stack — a mail
       preview, a test that renders the layout directly.
    */
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

    @php
        // Branding and CurrentWorkspace are container singletons, not static facades.
        $branding = app(\App\Support\Branding::class);
        $workspace ??= app(\App\Support\CurrentWorkspace::class)->get();
    @endphp

    <title>@yield('title', $title ?? '') {{ ($title ?? false) ? '·' : '' }} {{ $branding->name() }}</title>

    <link rel="icon" href="{{ $branding->favicon() }}" type="image/svg+xml">
    {{-- Served by WebManifestController, so its name and direction follow this page's. --}}
    <link rel="manifest" href="{{ route('manifest') }}">

    {{--
        Theme is applied before first paint. Doing this in the bundle instead would
        show a light flash on every navigation for dark-mode users.
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

    {{--
        The workspace accent overrides the brand default without a rebuild. Only the
        semantic token is reassigned, so every component that references var(--accent)
        follows automatically.
    --}}
    @if ($accent = $workspace?->accent_color)
        <style>:root { --accent: {{ $accent }}; --accent-hover: color-mix(in oklab, {{ $accent }} 85%, black); }</style>
    @endif

    @stack('head')
</head>
<body class="h-full antialiased" x-data="shortcuts">

<div class="flex h-full overflow-hidden">

    {{-- ------------------------------------------------------------------ --}}
    {{-- Sidebar                                                            --}}
    {{-- ------------------------------------------------------------------ --}}
    <div x-data="{
            collapsed: $persist(false).as('planvio.sidebar.collapsed'),
            mobileOpen: false,
         }"
         x-on:toggle-sidebar.window="collapsed = !collapsed"
         x-on:open-mobile-nav.window="mobileOpen = true"
         class="contents">

        {{-- Mobile scrim --}}
        <div x-show="mobileOpen" x-cloak x-transition.opacity
             x-on:click="mobileOpen = false"
             class="fixed inset-0 z-40 bg-ink-950/40 lg:hidden" aria-hidden="true"></div>

        {{--
            The closed mobile rail sits one width off the *inline start* edge, which is the
            left in English and the right in Arabic. Chosen in PHP rather than with a
            direction-aware class because the desktop reset below it (lg:translate-x-0) has
            to be able to win, and a custom class outside Tailwind's utility layer would
            outrank it at every breakpoint.
        --}}
        @php($offscreen = $direction === 'rtl' ? 'translate-x-full' : '-translate-x-full')

        <aside :class="collapsed ? 'lg:w-[3.75rem]' : 'lg:w-60'"
               x-bind:class="mobileOpen ? 'translate-x-0' : '{{ $offscreen }} lg:translate-x-0'"
               class="fixed inset-y-0 start-0 z-50 flex w-64 shrink-0 flex-col border-e
                      border-[var(--line-subtle)] bg-[var(--surface-panel)]
                      transition-[width,transform] duration-200 ease-[cubic-bezier(0.32,0.72,0,1)]
                      lg:static lg:z-auto">

            {{-- Workspace switcher --}}
            <div class="flex h-14 shrink-0 items-center gap-1 px-2.5">
                @include('app.partials.workspace-switcher')
            </div>

            {{-- Primary navigation --}}
            <nav class="scrollbar-thin flex-1 space-y-4 overflow-y-auto px-2 pb-4" aria-label="{{ __('Main') }}">
                <div class="space-y-0.5">
                    <x-app.nav-item :href="route('app.home', $workspace)" icon="icon.home"
                                    :active="request()->routeIs('app.home')" :label="__('Home')" />
                    <x-app.nav-item :href="route('app.inbox', $workspace)" icon="icon.inbox"
                                    :active="request()->routeIs('app.inbox')" :label="__('Inbox')"
                                    :badge="$unreadCount ?? null" />
                    <x-app.nav-item :href="route('app.my-tasks', $workspace)" icon="icon.check-circle"
                                    :active="request()->routeIs('app.my-tasks')" :label="__('My Tasks')" />
                    <x-app.nav-item :href="route('app.calendar', $workspace)" icon="icon.calendar"
                                    :active="request()->routeIs('app.calendar')" :label="__('Calendar')" />
                    <x-app.nav-item :href="route('app.time', $workspace)" icon="icon.clock"
                                    :active="request()->routeIs('app.time')" :label="__('My Time')" />
                    <x-app.nav-item :href="route('app.reports', $workspace)" icon="icon.chart"
                                    :active="request()->routeIs('app.reports')" :label="__('Reports')" />
                    @can('ai.use', $workspace)
                        <x-app.nav-item :href="route('app.ai', $workspace)" icon="icon.sparkles"
                                        :active="request()->routeIs('app.ai')" :label="__('AI')" />
                    @endcan
                </div>

                @include('app.partials.sidebar-projects')

                <div x-show="!collapsed" class="space-y-0.5">
                    <p class="px-2 pb-1 text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
                        {{ __('Workspace') }}
                    </p>
                    <x-app.nav-item :href="route('app.projects.index', $workspace)" icon="icon.folder"
                                    :active="request()->routeIs('app.projects.index')" :label="__('Projects')" />
                    <x-app.nav-item :href="route('app.teams', $workspace)" icon="icon.users"
                                    :active="request()->routeIs('app.teams')" :label="__('Teams')" />
                    @can('workspace.manage', $workspace)
                        <x-app.nav-item :href="route('app.settings', $workspace)" icon="icon.cog"
                                        :active="request()->routeIs('app.settings*')" :label="__('Settings')" />
                    @endcan
                </div>
            </nav>

            {{-- Collapse toggle: desktop only, since mobile uses the scrim. --}}
            <div class="hidden shrink-0 border-t border-[var(--line-subtle)] p-2 lg:block">
                <button type="button" x-on:click="collapsed = !collapsed"
                        class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-xs
                               text-[var(--text-muted)] transition-colors hover:bg-[var(--surface-hover)]
                               hover:text-[var(--text-DEFAULT)]"
                        :aria-label="collapsed ? '{{ __('Expand sidebar') }}' : '{{ __('Collapse sidebar') }}'">
                    {{-- Points at the edge the rail folds into, so it mirrors with the rail. --}}
                    <svg class="flip-rtl size-4 shrink-0 transition-transform duration-200"
                         :class="collapsed && 'rotate-180'"
                         viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path d="M12 6 8 10l4 4" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span x-show="!collapsed" x-cloak>{{ __('Collapse') }}</span>
                </button>
            </div>
        </aside>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Main column                                                        --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="flex min-w-0 flex-1 flex-col">

        <header class="flex h-14 shrink-0 items-center gap-2 border-b border-[var(--line-subtle)]
                       bg-[var(--surface-panel)] px-3 sm:px-4">

            <button type="button" x-on:click="$dispatch('open-mobile-nav')"
                    class="-ms-1 grid size-8 place-items-center rounded-md text-[var(--text-muted)]
                           transition-colors hover:bg-[var(--surface-hover)] lg:hidden"
                    aria-label="{{ __('Open navigation') }}">
                <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path d="M3 6h14M3 10h14M3 14h14" stroke-linecap="round"/>
                </svg>
            </button>

            <div class="min-w-0 flex-1">
                @isset($breadcrumbs)
                    {{ $breadcrumbs }}
                @else
                    <h1 dir="auto" class="truncate text-sm font-semibold text-[var(--text-strong)]">
                        {{ $header ?? $title ?? '' }}
                    </h1>
                @endisset
            </div>

            {{-- Search doubles as the command palette entry point. --}}
            <button type="button" x-on:click="$dispatch('open-command-palette')"
                    class="hidden h-8 items-center gap-2 rounded-md border border-[var(--line-DEFAULT)]
                           bg-[var(--surface-sunken)] ps-2.5 pe-1.5 text-xs text-[var(--text-subtle)]
                           transition-colors hover:border-[var(--line-strong)] sm:flex sm:w-56 lg:w-72">
                <svg class="size-3.5 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                    <circle cx="9" cy="9" r="5.5"/><path d="m13 13 4 4" stroke-linecap="round"/>
                </svg>
                <span class="flex-1 text-start">{{ __('Search or ask AI') }}</span>
                <kbd class="rounded border border-[var(--line-subtle)] bg-[var(--surface-panel)]
                            px-1 text-[10px] font-medium">{{ str_contains(request()->userAgent() ?? '', 'Mac') ? '⌘' : 'Ctrl' }}K</kbd>
            </button>

            <x-ui.button variant="ghost" size="md" icon-only x-on:click="$dispatch('open-command-palette')"
                         class="sm:hidden" :aria-label="__('Search')">
                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                    <circle cx="9" cy="9" r="5.5"/><path d="m13 13 4 4" stroke-linecap="round"/>
                </svg>
            </x-ui.button>

            @can('ai.use', $workspace)
                <x-ui.tooltip :label="__('Ask Planvio AI').'  ·  A'">
                    <x-ui.button variant="ghost" size="md" icon-only x-on:click="$dispatch('open-ai-panel')"
                                 :aria-label="__('Ask Planvio AI')">
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10 1.5 11.7 6l4.5 1.7-4.5 1.7L10 14l-1.7-4.6L3.8 7.7 8.3 6 10 1.5ZM4.6 12.4l.85 2.25L7.7 15.5l-2.25.85-.85 2.25-.85-2.25L1.5 15.5l2.25-.85.85-2.25Z"/>
                        </svg>
                    </x-ui.button>
                </x-ui.tooltip>
            @endcan

            @include('app.partials.create-menu')
            @include('app.partials.notifications-menu')
            @include('app.partials.user-menu')
        </header>

        <main class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
            {{ $slot }}
        </main>
    </div>
</div>

@include('app.partials.command-palette')
@include('app.partials.shortcuts-help')
@include('app.partials.toasts')

@can('ai.use', $workspace)
    @livewire('app.ai.panel')
@endcan

{{--
    Task detail and quick create are mounted once, here, so anything on any page can open
    them with a single browser event. Both render an empty element and issue no query until
    they are actually used, which is what keeps them off the cost of every page.
--}}
@if ($workspace)
    @livewire('app.tasks.task-drawer')
    @livewire('app.tasks.quick-create')
@endif

@stack('modals')
@stack('scripts')
</body>
</html>
