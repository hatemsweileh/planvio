@php
    use App\Support\Bidi;
    use App\Support\Formats;

    $canUpload = auth()->user()->can('create', [\App\Models\Attachment::class, $project]);
    $counts = $this->sourceCounts;
    $bytes = $this->totalBytes;
    $humanTotal = $bytes < 1024
        ? $bytes.' B'
        : Formats::number($bytes / (1024 ** min((int) floor(log(max($bytes, 1), 1024)), 3)), 1)
          .' '.['B', 'KB', 'MB', 'GB'][min((int) floor(log(max($bytes, 1), 1024)), 3)];
@endphp

<div class="page py-6">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Header                                                           --}}
    {{-- ---------------------------------------------------------------- --}}
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-xs text-[var(--text-muted)]">
                <a href="{{ route('app.projects.show', [$workspace, $project]) }}" wire:navigate
                   class="hover:text-[var(--text-DEFAULT)]">{{ $project->name }}</a>
            </p>
            <h1 class="mt-0.5 text-lg font-semibold tracking-tight text-[var(--text-strong)]">
                {{ __('Files') }}
            </h1>
            <p class="mt-1 text-xs text-[var(--text-muted)]">
                {{ trans_choice('{0} Nothing uploaded yet|{1} :count file|[2,*] :count files', $counts['all'], ['count' => $counts['all']]) }}
                @if ($counts['all'] > 0)
                    <span aria-hidden="true">&middot;</span> <x-ui.bidi>{{ $humanTotal }}</x-ui.bidi>
                @endif
            </p>
        </div>

        @if ($canUpload)
            <div x-data="{ uploading: false, progress: 0 }"
                 x-on:livewire-upload-start="uploading = true; progress = 0"
                 x-on:livewire-upload-finish="uploading = false"
                 x-on:livewire-upload-cancel="uploading = false"
                 x-on:livewire-upload-error="uploading = false"
                 x-on:livewire-upload-progress="progress = $event.detail.progress"
                 class="flex items-center gap-2">

                <div x-show="uploading" x-cloak class="w-32" role="progressbar"
                     aria-label="{{ __('Uploading') }}" x-bind:aria-valuenow="progress"
                     aria-valuemin="0" aria-valuemax="100">
                    <div class="h-1.5 overflow-hidden rounded-full bg-[var(--surface-active)]">
                        <div class="h-full rounded-full bg-[var(--accent)] transition-[width] duration-150"
                             x-bind:style="`width: ${progress}%`"></div>
                    </div>
                    <p class="mt-1 text-2xs tabular-nums text-[var(--text-subtle)]">
                        <span x-text="progress"></span>%
                    </p>
                </div>

                <label class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md border
                              border-transparent bg-[var(--accent)] px-3 text-sm font-medium text-white
                              shadow-xs transition-colors hover:bg-[var(--accent-hover)]
                              focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-[var(--accent)]">
                    <x-icon.plus class="size-4" />
                    {{ __('Upload files') }}
                    <input type="file" multiple wire:model="uploads" class="sr-only">
                </label>
            </div>
        @endif
    </header>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Refusals. Specific, and they stay until dismissed.               --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($this->rejections !== [])
        <div class="mt-4 rounded-lg border border-critical-500/40 bg-critical-50 p-3 dark:bg-critical-950/40"
             role="alert">
            <div class="flex items-start gap-2.5">
                <x-icon.warning class="mt-0.5 size-4 shrink-0 text-critical-600" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-critical-700 dark:text-critical-100">
                        {{ trans_choice(
                            '{1} One file was not accepted|[2,*] :count files were not accepted',
                            count($this->rejections),
                            ['count' => count($this->rejections)],
                        ) }}
                    </p>
                    <ul class="mt-1.5 space-y-1">
                        @foreach ($this->rejections as $rejection)
                            {{-- The file name is isolated: a name carrying digits and a dot,
                                 such as `2026-09-02.png`, is split by the paragraph's
                                 direction and comes back as `png.02-09-2026`. --}}
                            <li class="text-xs leading-relaxed text-critical-700 dark:text-critical-100">
                                <span class="font-medium" dir="auto">{{ Bidi::numbers((string) $rejection['name']) }}</span>
                                <span aria-hidden="true">&mdash;</span>
                                {{ $rejection['message'] }}
                            </li>
                        @endforeach
                    </ul>
                </div>
                <x-ui.button variant="ghost" size="sm" icon-only wire:click="dismissRejections"
                             :aria-label="__('Dismiss')">
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path d="m5 5 10 10M15 5 5 15" stroke-linecap="round"/>
                    </svg>
                </x-ui.button>
            </div>
        </div>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Filters. The source chips are the grouping.                      --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mt-5 flex flex-wrap items-center gap-2">
        <x-ui.tabs variant="pill" class="max-w-full overflow-x-auto no-scrollbar">
            @foreach ($this->sourceLabels() as $key => $label)
                <x-ui.tab variant="pill" :active="$source === $key" wire:click="setSource('{{ $key }}')"
                          :count="$counts[$key] ?? 0">
                    {{ $label }}
                </x-ui.tab>
            @endforeach
        </x-ui.tabs>

        <div class="ms-auto flex flex-wrap items-center gap-2">
            <div class="w-full sm:w-56">
                <label for="files-search" class="sr-only">{{ __('Search files') }}</label>
                <x-ui.input id="files-search" type="search" icon="icon.search"
                            wire:model.live.debounce.300ms="search" busy-target="search"
                            :placeholder="__('Search by file name')" />
            </div>

            <div class="w-40">
                <label for="files-type" class="sr-only">{{ __('File type') }}</label>
                <x-ui.select id="files-type" wire:model.live="type">
                    @foreach ($this->typeOptions() as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <x-ui.tabs variant="pill" :aria-label="__('Layout')">
                <x-ui.tab variant="pill" :active="$view === 'grid'" wire:click="setView('grid')"
                          icon="icon.board" :aria-label="__('Grid view')">
                    <span class="sr-only">{{ __('Grid') }}</span>
                </x-ui.tab>
                <x-ui.tab variant="pill" :active="$view === 'list'" wire:click="setView('list')"
                          icon="icon.list" :aria-label="__('List view')">
                    <span class="sr-only">{{ __('List') }}</span>
                </x-ui.tab>
            </x-ui.tabs>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- The files                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mt-4" wire:loading.class="opacity-60" wire:target="search,type,source,setSource,setType">
        @if ($this->files->isEmpty())
            <x-ui.card flush>
                @if ($this->hasFilters())
                    <x-ui.empty-state icon="icon.search"
                                      :title="__('No file matches those filters')"
                                      :description="__('Try a different type, another source, or clear the search.')">
                        <x-slot:actions>
                            <x-ui.button variant="secondary" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="icon.paperclip"
                                      :title="__('No files in this project yet')"
                                      :description="__('Anything attached to a task, a comment, a milestone or a wiki page shows up here too — this is the whole project in one list.')">
                        <x-slot:actions>
                            @if ($canUpload)
                                <label class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md
                                              border border-transparent bg-[var(--accent)] px-3 text-sm
                                              font-medium text-white shadow-xs transition-colors
                                              hover:bg-[var(--accent-hover)]">
                                    <x-icon.plus class="size-4" />
                                    {{ __('Upload the first file') }}
                                    <input type="file" multiple wire:model="uploads" class="sr-only">
                                </label>
                            @endif
                            <x-ui.button variant="secondary" icon="icon.list"
                                         :href="route('app.projects.tasks', [$workspace, $project])" wire:navigate>
                                {{ __('Go to tasks') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            </x-ui.card>

        @elseif ($view === 'grid')
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                @foreach ($this->files as $file)
                    @php $origin = $this->sourceOf($file); @endphp

                    <div wire:key="file-{{ $file->getKey() }}"
                         class="group/file flex flex-col overflow-hidden rounded-lg border border-[var(--line-subtle)]
                                bg-[var(--surface-panel)] shadow-panel transition-shadow hover:shadow-raised">

                        <a href="{{ route('attachments.download', $file) }}"
                           class="relative block aspect-4/3 overflow-hidden bg-[var(--surface-sunken)]">
                            @if ($file->is_image)
                                <img src="{{ route('attachments.download', $file) }}"
                                     alt="{{ $file->original_name }}"
                                     class="size-full object-cover" loading="lazy">
                            @else
                                <span class="grid size-full place-items-center">
                                    <span class="rounded-md border border-[var(--line-subtle)] bg-[var(--surface-panel)]
                                                 px-2 py-1 text-xs font-semibold uppercase tracking-wide
                                                 text-[var(--text-muted)]">
                                        {{ \Illuminate\Support\Str::limit($file->extension, 5, '') }}
                                    </span>
                                </span>
                            @endif
                        </a>

                        <div class="flex min-w-0 flex-1 flex-col gap-1 p-2.5">
                            <a href="{{ route('attachments.download', $file) }}"
                               class="truncate text-xs font-medium text-[var(--text-strong)] hover:text-[var(--accent)]"
                               title="{{ Bidi::numbers($file->original_name) }}">
                                {{-- A file name is somebody else's string: its dates and versions are
                                     isolated so `خطة 2026-09-30.pdf` does not come back as
                                     `خطة pdf.30-09-2026`. --}}
                                {{ Bidi::numbers($file->original_name) }}
                            </a>

                            <p class="flex items-center gap-1 text-2xs text-[var(--text-subtle)]">
                                {{-- A suffixed number: '242 KB' is one run and reads left to right in every language. --}}
                                <span class="tabular-nums"><x-ui.bidi>{{ $file->human_size }}</x-ui.bidi></span>
                                <span aria-hidden="true">&middot;</span>
                                <span x-data="relativeTime('{{ $file->created_at?->toIso8601String() }}')"
                                      x-text="label"></span>
                            </p>

                            <div class="mt-auto flex items-center justify-between gap-1 pt-1">
                                @if ($origin['url'])
                                    <a href="{{ $origin['url'] }}" wire:navigate
                                       class="inline-flex min-w-0 items-center gap-1 text-2xs text-[var(--text-muted)]
                                              hover:text-[var(--accent)]"
                                       title="{{ $origin['title'] }}">
                                        <x-dynamic-component :component="$origin['icon']" class="size-3 shrink-0" />
                                        <span class="truncate">{{ $origin['label'] }}</span>
                                    </a>
                                @else
                                    <span class="inline-flex min-w-0 items-center gap-1 text-2xs text-[var(--text-subtle)]">
                                        <x-dynamic-component :component="$origin['icon']" class="size-3 shrink-0" />
                                        <span class="truncate">{{ $origin['label'] }}</span>
                                    </span>
                                @endif

                                <div class="flex shrink-0 items-center gap-1">
                                    <x-ui.avatar :user="$file->uploader" size="xs" />
                                    @can('delete', $file)
                                        <x-ui.button variant="ghost" size="xs" icon-only
                                                     class="opacity-0 focus-visible:opacity-100 group-hover/file:opacity-100"
                                                     wire:click="deleteFile({{ $file->getKey() }})"
                                                     wire:confirm="{{ __('Remove “:name”? It is removed from :source too.', ['name' => $file->original_name, 'source' => $origin['label']]) }}"
                                                     :aria-label="__('Remove :name', ['name' => $file->original_name])">
                                            <x-icon.trash class="size-3.5" />
                                        </x-ui.button>
                                    @endcan
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$this->files" class="mt-3 rounded-lg border border-[var(--line-subtle)]
                             bg-[var(--surface-panel)]" />

        @else
            {{-- List. Rows on a desktop, stacked cards under sm — the same markup either way. --}}
            <x-ui.card flush>
                <div class="hidden grid-cols-[minmax(0,3fr)_minmax(0,2fr)_5rem_7rem_2rem] gap-3 border-b
                            border-[var(--line-subtle)] px-3 py-2 text-2xs font-semibold uppercase
                            tracking-wide text-[var(--text-subtle)] sm:grid">
                    <span>{{ __('Name') }}</span>
                    <span>{{ __('Source') }}</span>
                    <span class="text-end">{{ __('Size') }}</span>
                    <span>{{ __('Added') }}</span>
                    <span class="sr-only">{{ __('Actions') }}</span>
                </div>

                <ul class="divide-y divide-[var(--line-subtle)]">
                    @foreach ($this->files as $file)
                        @php $origin = $this->sourceOf($file); @endphp

                        <li wire:key="row-{{ $file->getKey() }}"
                            class="group/row grid grid-cols-1 gap-1 px-3 py-2.5 transition-colors
                                   hover:bg-[var(--surface-hover)]
                                   sm:grid-cols-[minmax(0,3fr)_minmax(0,2fr)_5rem_7rem_2rem] sm:items-center sm:gap-3">

                            <a href="{{ route('attachments.download', $file) }}"
                               class="flex min-w-0 items-center gap-2.5">
                                <span class="grid size-8 shrink-0 place-items-center overflow-hidden rounded
                                             bg-[var(--surface-sunken)] text-[var(--text-subtle)]">
                                    @if ($file->is_image)
                                        <img src="{{ route('attachments.download', $file) }}" alt=""
                                             class="size-full object-cover" loading="lazy">
                                    @else
                                        <span class="text-[9px] font-semibold uppercase">
                                            {{ \Illuminate\Support\Str::limit($file->extension, 4, '') }}
                                        </span>
                                    @endif
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm text-[var(--text-strong)]">
                                        {{ Bidi::numbers($file->original_name) }}
                                    </span>
                                    <span class="block truncate text-2xs text-[var(--text-subtle)]">
                                        {{ $file->uploader?->name ?? __('Planvio') }}
                                    </span>
                                </span>
                            </a>

                            <span class="min-w-0 text-xs text-[var(--text-muted)]">
                                @if ($origin['url'])
                                    <a href="{{ $origin['url'] }}" wire:navigate
                                       class="inline-flex min-w-0 max-w-full items-center gap-1 hover:text-[var(--accent)]">
                                        <x-dynamic-component :component="$origin['icon']" class="size-3.5 shrink-0" />
                                        <span class="truncate">{{ $origin['title'] }}</span>
                                    </a>
                                @else
                                    <span class="inline-flex min-w-0 max-w-full items-center gap-1">
                                        <x-dynamic-component :component="$origin['icon']" class="size-3.5 shrink-0" />
                                        <span class="truncate">{{ $origin['title'] }}</span>
                                    </span>
                                @endif
                            </span>

                            <span class="text-xs tabular-nums text-[var(--text-muted)] sm:text-end">
                                <x-ui.bidi>{{ $file->human_size }}</x-ui.bidi>
                            </span>

                            <span class="text-xs text-[var(--text-muted)]"
                                  x-data="relativeTime('{{ $file->created_at?->toIso8601String() }}')"
                                  x-text="label"></span>

                            <span class="justify-self-end">
                                @can('delete', $file)
                                    <x-ui.button variant="ghost" size="sm" icon-only
                                                 class="opacity-0 focus-visible:opacity-100 group-hover/row:opacity-100"
                                                 wire:click="deleteFile({{ $file->getKey() }})"
                                                 wire:confirm="{{ __('Remove “:name”? It is removed from :source too.', ['name' => $file->original_name, 'source' => $origin['label']]) }}"
                                                 :aria-label="__('Remove :name', ['name' => $file->original_name])">
                                        <x-icon.trash class="size-4" />
                                    </x-ui.button>
                                @endcan
                            </span>
                        </li>
                    @endforeach
                </ul>

                <x-ui.pagination :paginator="$this->files" />
            </x-ui.card>
        @endif
    </div>
</div>
