{{--
    The translation editor.

    Inline styles rather than utility classes, for the reason the AI usage page gives: the
    Filament panel does not load the product's Tailwind build, so anything here has to stand on
    the panel's own CSS variables with literal fallbacks.

    Every input is keyed by the row hash rather than by the translation key. A key is an English
    sentence — "Nothing to show. Try a different filter." — and Livewire reads a dot in a
    property path as a level of nesting, so binding by key would silently write into a tree
    nobody asked for.
--}}
@php
    $muted = 'color: var(--fi-color-gray-500, #6b7280);';
    $mono = "font-family: var(--fi-font-mono, ui-monospace, 'SFMono-Regular', Menlo, monospace);";
    $control = 'padding: 0.4rem 0.6rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent; color: inherit; font-size: 0.875rem;';
    $cell = 'padding: 0.6rem 0.5rem; vertical-align: top; border-top: 1px solid rgba(127, 127, 127, 0.18);';
    /*
     | Upper-cased and tracked out in Latin, and neither in Arabic: the script has no letter
     | case, and tracking a joined script stretches the joins rather than opening the word.
     | Decided here in PHP because an inline style outranks any rule that could say it once
     | — the panel does not load the product's stylesheet.
     */
    $head = 'padding: 0.5rem; text-align: start; font-weight: 500; font-size: 0.75rem;'
        . (str_starts_with(app()->getLocale(), 'ar') ? '' : ' text-transform: uppercase; letter-spacing: 0.04em;')
        . $muted;

    $queued = \App\Jobs\TranslateLocaleJob::QUEUED;
    $runningState = \App\Jobs\TranslateLocaleJob::RUNNING;
    $failedState = \App\Jobs\TranslateLocaleJob::FAILED;
    $status = $progress['status'] ?? null;
    $running = in_array($status, [$queued, $runningState], true);
@endphp

<x-filament-panels::page>

    {{-- ------------------------------------------------------------------
         What is being edited
    ------------------------------------------------------------------ --}}
    <x-filament::section :heading="__('Language and catalogue')">
        <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end;">
            <label style="display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.75rem;">
                <span style="{{ $muted }}">{{ __('Language') }}</span>
                <select wire:model.live="locale" style="{{ $control }} min-width: 12rem;">
                    @foreach ($locales as $option)
                        <option value="{{ $option->code }}">{{ $option->label() }} ({{ $option->code }})</option>
                    @endforeach
                </select>
            </label>

            <label style="display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.75rem;">
                <span style="{{ $muted }}">{{ __('Catalogue') }}</span>
                <select wire:model.live="catalogue" style="{{ $control }} min-width: 12rem;">
                    @foreach ($catalogues as $name)
                        <option value="{{ $name }}">{{ $catalogueLabels[$name] }}</option>
                    @endforeach
                </select>
            </label>

            <label style="display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.75rem;">
                <span style="{{ $muted }}">{{ __('Show') }}</span>
                <select wire:model.live="filter" style="{{ $control }} min-width: 12rem;">
                    @foreach ($filters as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label style="display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.75rem; flex: 1 1 16rem;">
                <span style="{{ $muted }}">{{ __('Search') }}</span>
                <input
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="{{ __('English, translation or key') }}"
                    style="{{ $control }} width: 100%;"
                >
            </label>
        </div>

        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem;">
            <x-filament::badge color="gray">
                {{ __(':count keys', ['count' => number_format($counts['total'])]) }}
            </x-filament::badge>
            <x-filament::badge :color="$counts['untranslated'] > 0 ? 'warning' : 'success'">
                {{ __(':count untranslated', ['count' => number_format($counts['untranslated'])]) }}
            </x-filament::badge>
            <x-filament::badge :color="$counts['unreviewed'] > 0 ? 'info' : 'gray'">
                {{ __(':count awaiting review', ['count' => number_format($counts['unreviewed'])]) }}
            </x-filament::badge>
            @if ($counts['stale'] > 0)
                <x-filament::badge color="danger">
                    {{ __(':count where the English changed since', ['count' => number_format($counts['stale'])]) }}
                </x-filament::badge>
            @endif
            @if ($counts['orphans'] > 0)
                <x-filament::badge color="gray">
                    {{ __(':count stored for keys the product no longer uses', ['count' => number_format($counts['orphans'])]) }}
                </x-filament::badge>
            @endif
        </div>

        @if ($this->locale === $source)
            <p style="margin-top: 0.75rem; font-size: 0.8125rem; {{ $muted }}">
                {{ __('This is the language the product is written in. Editing it here rewords Planvio itself — useful for saying "client" where the product says "customer" — and the change survives an upgrade, which editing a file inside the release does not.') }}
            </p>
        @endif
    </x-filament::section>

    {{-- ------------------------------------------------------------------
         The AI pass
    ------------------------------------------------------------------ --}}
    @if ($aiRefusal !== null)
        <x-filament::section :heading="__('AI-assisted translation')" collapsible collapsed>
            <p style="font-size: 0.875rem;">{{ $aiRefusal }}</p>
            <p style="margin-top: 0.5rem; font-size: 0.8125rem; {{ $muted }}">
                {{ __('The first pass is offered only when the AI layer is configured and switched on. Everything else on this page works without it.') }}
            </p>
        </x-filament::section>
    @elseif ($progress !== null)
        <x-filament::section :heading="__('AI translation pass')">
            @php
                $total = max(1, (int) ($progress['total'] ?? 0));
                $done = (int) ($progress['processed'] ?? 0);
                $percent = min(100, (int) round(($done / $total) * 100));
                $failed = $status === $failedState;
                $label = match ($status) {
                    $queued => __('Queued'),
                    $runningState => __('Running'),
                    $failedState => __('Stopped'),
                    default => __('Finished'),
                };
            @endphp

            {{-- Polls the whole component while a pass is in flight, and stops the moment it
                 is not: the bar has to move on its own, and an admin screen that keeps asking
                 for ever is a cost nobody chose. --}}
            @if ($running)
                <div wire:poll.3s></div>
            @endif

            <div style="display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: center;">
                <x-filament::badge :color="$failed ? 'danger' : ($running ? 'info' : 'success')">
                    {{ $label }}
                </x-filament::badge>

                <span style="font-size: 0.875rem; font-variant-numeric: tabular-nums;">
                    {{ __(':done of :total lines · :stored stored · :discarded discarded', [
                        'done' => number_format($done),
                        'total' => number_format((int) ($progress['total'] ?? 0)),
                        'stored' => number_format((int) ($progress['stored'] ?? 0)),
                        'discarded' => number_format((int) ($progress['discarded'] ?? 0)),
                    ]) }}
                </span>
            </div>

            <div style="margin-top: 0.75rem; height: 0.375rem; border-radius: 9999px; overflow: hidden; background: rgba(127, 127, 127, 0.22);">
                <div style="height: 100%; width: {{ $percent }}%; background: var(--fi-color-primary-500, #5379c1);"></div>
            </div>

            @if (filled($progress['message'] ?? null))
                <p style="margin-top: 0.75rem; font-size: 0.875rem;">{{ $progress['message'] }}</p>
            @endif

            <p style="margin-top: 0.75rem; font-size: 0.8125rem; {{ $muted }}">
                {{ __('Everything the model wrote is stored unreviewed. A discarded line lost a placeholder and was never stored, so it is still listed as untranslated.') }}
            </p>
        </x-filament::section>
    @endif

    {{-- ------------------------------------------------------------------
         Export and import
    ------------------------------------------------------------------ --}}
    <x-filament::section
        :heading="__('Export and import')"
        :description="__('The same JSON document lang:export writes and lang:import reads, so a file can make the round trip through an external translator and back.')"
        collapsible
        collapsed
    >
        <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center;">
            <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" wire:click="export(false)">
                {{ __('Export everything') }}
            </x-filament::button>
            <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" wire:click="export(true)">
                {{ __('Export what is untranslated') }}
            </x-filament::button>
        </div>

        <div style="margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid rgba(127, 127, 127, 0.18);">
            <label style="display: block; font-size: 0.75rem; {{ $muted }}">{{ __('Translated JSON file') }}</label>
            <input
                type="file"
                accept="application/json,.json"
                wire:model="upload"
                style="margin-top: 0.35rem; font-size: 0.875rem;"
            >

            @error('upload')
                <p style="margin-top: 0.35rem; font-size: 0.8125rem; color: var(--fi-color-danger-600, #dc2626);">{{ $message }}</p>
            @enderror

            <label style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.75rem; font-size: 0.875rem;">
                <input type="checkbox" wire:model="importReviewed">
                <span>{{ __('Mark every imported line as reviewed') }}</span>
            </label>
            <p style="margin-top: 0.25rem; font-size: 0.8125rem; {{ $muted }}">
                {{ __('Only if a person you trust produced the file. Left off, the lines arrive unreviewed and show up under Needs review.') }}
            </p>

            <div style="margin-top: 0.75rem;">
                <x-filament::button color="gray" icon="heroicon-o-arrow-up-tray" wire:click="previewImport">
                    {{ __('Preview this import') }}
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>

    {{-- ------------------------------------------------------------------
         What the import would do
    ------------------------------------------------------------------ --}}
    @if ($import !== [])
        <x-filament::section
            :heading="__('This import has not been applied yet')"
            :description="__('Nothing has been written. Read the counts, then apply or discard.')"
        >
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead>
                        <tr>
                            <th style="{{ $head }}">{{ __('Catalogue') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('New') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('Replaced') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('Unchanged') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('Cleared') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('Not in the product') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('Refused') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($import['catalogues'] as $name => $figures)
                            <tr>
                                <td style="{{ $cell }}">{{ $name === '*' ? __('Literal strings') : $name }}</td>
                                @foreach (['new', 'changed', 'unchanged', 'cleared', 'unknown', 'rejected'] as $measure)
                                    <td style="{{ $cell }} text-align: end; font-variant-numeric: tabular-nums;">
                                        {{ number_format($figures[$measure]) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($import['totals']['rejected'] > 0)
                <div style="margin-top: 1rem;">
                    <p style="font-size: 0.875rem; font-weight: 500;">
                        {{ __('These lines will not be imported: they dropped a placeholder the English needs.') }}
                    </p>
                    <ul style="margin-top: 0.5rem; font-size: 0.8125rem; {{ $muted }}">
                        @foreach ($import['rejections'] as $rejection)
                            <li style="padding: 0.15rem 0;">
                                <span style="{{ $mono }}">{{ implode(', ', array_map(fn ($name) => ':' . $name, $rejection['missing'])) }}</span>
                                — {{ \App\Services\Translation\Placeholders::excerpt($rejection['key'], 90) }}
                            </li>
                        @endforeach
                    </ul>
                    @if ($import['rejections_truncated'])
                        <p style="margin-top: 0.35rem; font-size: 0.8125rem; {{ $muted }}">
                            {{ __('Only the first :count are listed.', ['count' => \App\Services\Translation\TranslationImportPreview::MAX_REJECTIONS]) }}
                        </p>
                    @endif
                </div>
            @endif

            @if ($import['totals']['unknown'] > 0)
                <p style="margin-top: 1rem; font-size: 0.8125rem; {{ $muted }}">
                    {{ __(':count keys in this file are not in the product any more. They are skipped: a row for a key nothing renders could never appear. A large number here usually means the file was exported from a different version of Planvio.', [
                        'count' => number_format($import['totals']['unknown']),
                    ]) }}
                </p>
            @endif

            <div style="display: flex; gap: 0.75rem; margin-top: 1.25rem;">
                <x-filament::button color="primary" wire:click="applyImport">
                    {{ __('Import :count lines', ['count' => number_format($import['writes'])]) }}
                </x-filament::button>
                <x-filament::button color="gray" wire:click="discardImport">
                    {{ __('Discard') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- ------------------------------------------------------------------
         The matrix
    ------------------------------------------------------------------ --}}
    <x-filament::section
        :heading="__('Lines')"
        :description="__('Showing :from–:to of :matched.', [
            'from' => number_format($matched === 0 ? 0 : (($this->page - 1) * $perPage) + 1),
            'to' => number_format(min($matched, $this->page * $perPage)),
            'matched' => number_format($matched),
        ])"
    >
        @if ($rows === [])
            <p style="font-size: 0.875rem; {{ $muted }}">
                {{ __('Nothing matches. Try a different filter, or clear the search.') }}
            </p>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead>
                        <tr>
                            <th style="{{ $head }} width: 40%;">{{ __('English') }}</th>
                            <th style="{{ $head }} width: 45%;">{{ __('Translation') }}</th>
                            <th style="{{ $head }} width: 15%;">{{ __('Reviewed') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr wire:key="line-{{ $row['hash'] }}">
                                <td style="{{ $cell }}">
                                    <div style="white-space: pre-wrap;">{{ $row['reference'] }}</div>

                                    @if ($this->catalogue !== '*')
                                        <div style="margin-top: 0.25rem; font-size: 0.75rem; {{ $mono }} {{ $muted }}">{{ $this->catalogue }}.{{ $row['key'] }}</div>
                                    @endif

                                    <div style="display: flex; flex-wrap: wrap; gap: 0.25rem; margin-top: 0.35rem;">
                                        @foreach ($row['placeholders'] as $placeholder)
                                            <span style="{{ $mono }} font-size: 0.6875rem; padding: 0.05rem 0.35rem; border-radius: 0.25rem; background: rgba(127, 127, 127, 0.16);">:{{ $placeholder }}</span>
                                        @endforeach

                                        @if ($row['stale'])
                                            <span style="font-size: 0.6875rem; padding: 0.05rem 0.35rem; border-radius: 0.25rem; background: var(--fi-color-danger-500, #ef4444); color: #fff;">
                                                {{ __('English changed since') }}
                                            </span>
                                        @endif
                                    </div>
                                </td>

                                <td style="{{ $cell }}">
                                    <textarea
                                        rows="{{ max(2, min(6, (int) ceil(mb_strlen($row['reference']) / 60))) }}"
                                        dir="{{ $direction }}"
                                        wire:model="values.{{ $row['hash'] }}"
                                        placeholder="{{ __('Not translated') }}"
                                        style="{{ $control }} width: 100%; resize: vertical; line-height: 1.45;"
                                    ></textarea>
                                </td>

                                <td style="{{ $cell }}">
                                    <label style="display: flex; align-items: center; gap: 0.4rem;">
                                        <input type="checkbox" wire:model="reviewed.{{ $row['hash'] }}">
                                        <span style="font-size: 0.8125rem; {{ $muted }}">
                                            {{ $row['reviewed'] ? __('Approved') : __('Not yet') }}
                                        </span>
                                    </label>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; justify-content: space-between; margin-top: 1.25rem;">
                <x-filament::button color="primary" wire:click="save">
                    {{ __('Save this page') }}
                </x-filament::button>

                @if ($pages > 1)
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <x-filament::button
                            color="gray"
                            size="sm"
                            :disabled="$this->page <= 1"
                            wire:click="goToPage({{ max(1, $this->page - 1) }})"
                        >
                            {{ __('Previous') }}
                        </x-filament::button>

                        <span style="font-size: 0.8125rem; {{ $muted }}">
                            {{ __('Page :page of :pages', ['page' => number_format($this->page), 'pages' => number_format($pages)]) }}
                        </span>

                        <x-filament::button
                            color="gray"
                            size="sm"
                            :disabled="$this->page >= $pages"
                            wire:click="goToPage({{ min($pages, $this->page + 1) }})"
                        >
                            {{ __('Next') }}
                        </x-filament::button>
                    </div>
                @endif
            </div>

            <p style="margin-top: 0.75rem; font-size: 0.8125rem; {{ $muted }}">
                {{ __('A line missing a placeholder the English needs refuses the whole save, and says which. Clearing a translation stores nothing, so the line falls back to the English it was written in.') }}
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
