@php
    $sections = $this->available;
    $current = $sections[$section] ?? null;
@endphp

<div class="page py-6">

    <header class="mb-5">
        <h1 class="text-lg font-semibold tracking-tight text-[var(--text-strong)]">
            {{ __('Workspace settings') }}
        </h1>
        <p class="mt-1 max-w-prose text-sm leading-relaxed text-[var(--text-muted)]">
            {{ __('How :workspace looks, what its projects are made of, and what it is allowed to do on its own.', ['workspace' => $workspace->name]) }}
        </p>
    </header>

    <div class="flex flex-col gap-5 lg:flex-row lg:gap-6">

        {{-- Rail on a desktop, a scrolling strip of pills on a phone. --}}
        <nav class="shrink-0 lg:w-56" aria-label="{{ __('Settings sections') }}">
            <div class="flex gap-1 overflow-x-auto no-scrollbar lg:flex-col lg:overflow-visible">
                @foreach ($sections as $key => $meta)
                    <button type="button" wire:click="setSection('{{ $key }}')"
                            @if ($section === $key) aria-current="page" @endif
                            class="group flex h-8 shrink-0 items-center gap-2 rounded-md px-2 text-sm
                                   transition-colors duration-100 lg:h-auto lg:py-1.5
                                   {{ $section === $key
                                       ? 'bg-[var(--accent-soft)] font-medium text-[var(--accent-soft-text)]'
                                       : 'text-[var(--text-muted)] hover:bg-[var(--surface-hover)] hover:text-[var(--text-DEFAULT)]' }}">
                        <x-dynamic-component :component="$meta['icon']"
                            class="size-4 shrink-0 {{ $section === $key ? '' : 'text-[var(--text-subtle)]' }}" />
                        <span class="whitespace-nowrap">{{ $meta['label'] }}</span>
                    </button>
                @endforeach
            </div>
        </nav>

        <div class="min-w-0 flex-1">
            @if ($current === null)
                <x-ui.card flush>
                    <x-ui.empty-state icon="icon.shield"
                                      :title="__('Nothing here for your role')"
                                      :description="__('Workspace settings are managed by owners and administrators.')" />
                </x-ui.card>
            @else
                <div class="mb-3">
                    <h2 class="text-base font-semibold tracking-tight text-[var(--text-strong)]">
                        {{ $current['label'] }}
                    </h2>
                    <p class="mt-0.5 text-xs text-[var(--text-muted)]">{{ $current['description'] }}</p>
                </div>

                {{-- Keyed on the section so switching mounts a fresh component rather than
                     morphing one form's state onto another's. --}}
                @livewire($current['component'], ['workspace' => $workspace], key('settings-'.$section))
            @endif
        </div>
    </div>
</div>
