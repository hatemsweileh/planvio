<div class="page page-prose py-6">

    @include('livewire.app.profile._tabs')

    <form wire:submit="save" class="mt-5 space-y-4">

        {{-- ------------------------------------------------------------ --}}
        {{-- The master switch                                            --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card>
            <x-ui.checkbox wire:model.live="muteAll"
                           :label="__('Pause everything')"
                           :description="__('Nothing is sent to you at all — no inbox item, no email. Your settings below are kept.')" />
        </x-ui.card>

        <div @class(['pointer-events-none opacity-50' => $muteAll]) aria-hidden="{{ $muteAll ? 'true' : 'false' }}">

            {{-- -------------------------------------------------------- --}}
            {{-- Defaults per channel                                     --}}
            {{-- -------------------------------------------------------- --}}
            <x-ui.card :title="__('Where things reach you')"
                       :subtitle="__('Applies to every category unless you change one below.')">
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="flex items-start gap-2.5 rounded-lg border border-[var(--line-subtle)] p-3
                                  {{ $channels['database'] ? 'bg-[var(--accent-soft)]' : '' }}">
                        <input type="checkbox" @checked($channels['database'])
                               x-on:change="$wire.toggleAll('database', $event.target.checked)"
                               class="mt-0.5 size-4 shrink-0 rounded border-[var(--line-strong)]
                                      bg-[var(--surface-panel)] text-[var(--accent)]
                                      checked:border-[var(--accent)] checked:bg-[var(--accent)]
                                      focus:ring-2 focus:ring-[var(--accent-ring)]">
                        <span>
                            <span class="block text-sm font-medium text-[var(--text-strong)]">{{ __('Planvio inbox') }}</span>
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ __('The bell in the header and the Inbox screen.') }}
                            </span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2.5 rounded-lg border border-[var(--line-subtle)] p-3
                                  {{ $channels['mail'] ? 'bg-[var(--accent-soft)]' : '' }}">
                        <input type="checkbox" @checked($channels['mail'])
                               x-on:change="$wire.toggleAll('mail', $event.target.checked)"
                               class="mt-0.5 size-4 shrink-0 rounded border-[var(--line-strong)]
                                      bg-[var(--surface-panel)] text-[var(--accent)]
                                      checked:border-[var(--accent)] checked:bg-[var(--accent)]
                                      focus:ring-2 focus:ring-[var(--accent-ring)]">
                        <span>
                            <span class="block text-sm font-medium text-[var(--text-strong)]">{{ __('Email') }}</span>
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ __('Sent to :email.', ['email' => auth()->user()->email]) }}
                            </span>
                        </span>
                    </label>
                </div>
            </x-ui.card>

            {{-- -------------------------------------------------------- --}}
            {{-- Per category                                             --}}
            {{-- -------------------------------------------------------- --}}
            @foreach ($this->catalogue() as $group => $section)
                <x-ui.card class="mt-4" flush wire:key="group-{{ $group }}">
                    <x-slot:header>
                        <p class="text-sm font-semibold text-[var(--text-strong)]">{{ $section['title'] }}</p>
                        <p class="mt-0.5 text-xs text-[var(--text-muted)]">{{ $section['description'] }}</p>
                    </x-slot:header>

                    <x-slot:actions>
                        <div class="hidden gap-6 pe-1 text-2xs font-semibold uppercase tracking-wide
                                    text-[var(--text-subtle)] sm:flex">
                            <span class="w-10 text-center">{{ __('Inbox') }}</span>
                            <span class="w-10 text-center">{{ __('Email') }}</span>
                        </div>
                    </x-slot:actions>

                    <ul class="divide-y divide-[var(--line-subtle)]">
                        @foreach ($section['items'] as $category => $label)
                            <li class="flex items-center gap-3 px-3 py-2.5">
                                <span class="min-w-0 flex-1 text-sm text-[var(--text-DEFAULT)]">{{ $label }}</span>

                                <label class="flex w-10 shrink-0 justify-center">
                                    <span class="sr-only">
                                        {{ __(':label in the Planvio inbox', ['label' => $label]) }}
                                    </span>
                                    <input type="checkbox" wire:model="categories.{{ $category }}.database"
                                           class="size-4 rounded border-[var(--line-strong)] bg-[var(--surface-panel)]
                                                  text-[var(--accent)] checked:border-[var(--accent)]
                                                  checked:bg-[var(--accent)] focus:ring-2 focus:ring-[var(--accent-ring)]">
                                </label>

                                <label class="flex w-10 shrink-0 justify-center">
                                    <span class="sr-only">{{ __(':label by email', ['label' => $label]) }}</span>
                                    <input type="checkbox" wire:model="categories.{{ $category }}.mail"
                                           class="size-4 rounded border-[var(--line-strong)] bg-[var(--surface-panel)]
                                                  text-[var(--accent)] checked:border-[var(--accent)]
                                                  checked:bg-[var(--accent)] focus:ring-2 focus:ring-[var(--accent-ring)]">
                                </label>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-[var(--text-muted)]">
                {{ __('Anything you leave untouched follows the Planvio default, so it keeps improving as the product does.') }}
            </p>
            <div class="flex items-center gap-2">
                <span wire:loading wire:target="save" class="text-xs text-[var(--text-muted)]">{{ __('Saving…') }}</span>
                <x-ui.button type="submit" variant="primary">{{ __('Save preferences') }}</x-ui.button>
            </div>
        </div>
    </form>
</div>
