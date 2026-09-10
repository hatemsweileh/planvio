@php
    use App\Support\Bidi;
@endphp

<div class="flex h-full min-h-0 flex-col lg:flex-row" x-data="{ railOpen: false }">

    @include('livewire.app.projects._wiki-rail', [
        'workspace' => $workspace,
        'project' => $project,
        'activeId' => $page->getKey(),
    ])

    <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">

        {{-- ------------------------------------------------------------ --}}
        {{-- Page chrome                                                  --}}
        {{-- ------------------------------------------------------------ --}}
        <div class="sticky top-0 z-10 border-b border-[var(--line-subtle)] bg-[var(--surface-panel)]">
            <div class="mx-auto flex h-11 w-full max-w-3xl items-center gap-2 px-4 sm:px-6">

                <x-ui.button variant="ghost" size="sm" icon-only class="lg:hidden"
                             x-on:click="railOpen = !railOpen" :aria-label="__('Browse documents')">
                    <x-icon.list class="size-4" />
                </x-ui.button>

                <nav class="min-w-0 flex-1 overflow-x-auto no-scrollbar" aria-label="{{ __('Breadcrumb') }}">
                    <ol class="flex items-center gap-1 whitespace-nowrap text-xs text-[var(--text-muted)]">
                        <li>
                            <a href="{{ route('app.projects.wiki', [$workspace, $project]) }}" wire:navigate
                               class="transition-colors hover:text-[var(--text-DEFAULT)]">{{ __('Wiki') }}</a>
                        </li>
                        @foreach ($this->breadcrumb as $crumb)
                            <li aria-hidden="true" class="text-[var(--text-subtle)]">
                                <x-icon.chevron-right class="size-3 flip-rtl" />
                            </li>
                            <li class="min-w-0">
                                @if ($loop->last)
                                    <span class="font-medium text-[var(--text-DEFAULT)]"
                                          aria-current="page">{{ $crumb->title }}</span>
                                @else
                                    <a href="{{ route('app.projects.wiki.show', [$workspace, $project, $crumb]) }}"
                                       wire:navigate
                                       class="transition-colors hover:text-[var(--text-DEFAULT)]">{{ $crumb->title }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </nav>

                <div class="flex shrink-0 items-center gap-1">
                    @if ($this->aiAvailable)
                        @php
                            // Held in variables rather than written inline: the Blade
                            // compiler builds a pattern out of a directive's argument, and
                            // a paragraph of prose inside @js() is a pattern it chokes on.
                            // They are written into the attributes below with `Js::from`
                            // rather than the `@js` directive, because a Blade directive
                            // inside an `<x-…>` tag attribute is not compiled at all: the
                            // literal text `@js($summarisePrompt)` reached the browser and
                            // both of these items threw a syntax error instead of opening
                            // the panel. `{{ }}` renders a Htmlable unescaped, so what goes
                            // out is the same JSON the directive would have produced.
                            $summarisePrompt = __('Summarise the wiki page “:title” in the project :project, in five bullet points.', [
                                'title' => $page->title,
                                'project' => $project->name,
                            ]);
                            $tasksPrompt = __('Read the wiki page “:title” in the project :project and create tasks for the work it describes. Show me the list before creating anything.', [
                                'title' => $page->title,
                                'project' => $project->name,
                            ]);
                        @endphp

                        <x-ui.dropdown align="end" width="w-64">
                            <x-slot:trigger>
                                <x-ui.button variant="ghost" size="sm" icon="icon.sparkles"
                                             trailing-icon="icon.chevron-down">
                                    <span class="max-sm:sr-only">{{ __('AI') }}</span>
                                </x-ui.button>
                            </x-slot:trigger>

                            <x-ui.dropdown-item icon="icon.document"
                                x-on:click="$dispatch('open-ai-panel', { prompt: {{ \Illuminate\Support\Js::from($summarisePrompt) }} })">
                                {{ __('Summarise this page') }}
                            </x-ui.dropdown-item>

                            <x-ui.dropdown-item icon="icon.check-circle"
                                x-on:click="$dispatch('open-ai-panel', { prompt: {{ \Illuminate\Support\Js::from($tasksPrompt) }} })">
                                {{ __('Turn into tasks') }}
                            </x-ui.dropdown-item>
                        </x-ui.dropdown>
                    @endif

                    @if ($this->canEdit())
                        @if ($editing)
                            <x-ui.button variant="ghost" size="sm" wire:click="cancelEditing">
                                {{ __('Cancel') }}
                            </x-ui.button>
                            <x-ui.button variant="primary" size="sm" wire:click="save" wire:target="save">
                                {{ __('Save') }}
                            </x-ui.button>
                        @else
                            <x-ui.button variant="secondary" size="sm" wire:click="startEditing">
                                {{ __('Edit') }}
                            </x-ui.button>
                        @endif

                        <x-ui.dropdown align="end" width="w-56">
                            <x-slot:trigger>
                                <x-ui.button variant="ghost" size="sm" icon-only :aria-label="__('Page actions')">
                                    <x-icon.dots class="size-4" />
                                </x-ui.button>
                            </x-slot:trigger>

                            <x-ui.dropdown-item icon="icon.plus" wire:click="startCreate({{ $page->getKey() }})">
                                {{ __('New subpage') }}
                            </x-ui.dropdown-item>
                            <x-ui.dropdown-separator />
                            <x-ui.dropdown-item icon="icon.trash" danger
                                                wire:click="deletePage({{ $page->getKey() }})"
                                                wire:confirm="{{ __('Delete “:title”? Its subpages move up a level.', ['title' => $page->title]) }}">
                                {{ __('Delete page') }}
                            </x-ui.dropdown-item>
                        </x-ui.dropdown>
                    @endif
                </div>
            </div>
        </div>

        <article class="page page-prose py-6 sm:py-8">

            {{-- -------------------------------------------------------- --}}
            {{-- Title and byline                                          --}}
            {{-- -------------------------------------------------------- --}}
            @if ($editing)
                <x-ui.field :error="$errors->first('title')">
                    <input type="text" wire:model="title" maxlength="255"
                           aria-label="{{ __('Page title') }}"
                           class="w-full border-0 bg-transparent p-0 text-2xl font-semibold tracking-tight
                                  text-[var(--text-strong)] placeholder:text-[var(--text-subtle)]
                                  focus:outline-none focus:ring-0"
                           placeholder="{{ __('Untitled') }}">
                </x-ui.field>
            @else
                <h1 dir="auto" class="text-2xl font-semibold tracking-tight text-[var(--text-strong)]">
                    {{ $page->title }}
                </h1>
            @endif

            <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-[var(--text-muted)]">
                <x-ui.avatar :user="$page->editor ?? $page->author" size="xs" />
                <span>
                    {{ __('Last edited by :name', [
                        'name' => $page->editor?->name ?? $page->author?->name ?? __('Planvio'),
                    ]) }}
                </span>
                <span aria-hidden="true">&middot;</span>
                <span x-data="relativeTime('{{ $page->updated_at?->toIso8601String() }}')" x-text="label"></span>

                @if ($editing)
                    <span aria-hidden="true">&middot;</span>
                    <label for="wiki-visibility" class="sr-only">{{ __('Who can read it') }}</label>
                    <span class="inline-block w-52">
                        <x-ui.select id="wiki-visibility" size="sm" wire:model="visibility"
                                     :invalid="$errors->has('visibility')">
                            @foreach (\App\Enums\WikiVisibility::cases() as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </span>
                @else
                    <x-ui.badge :color="$page->visibility->color()" size="sm" dot>
                        {{ $page->visibility->label() }}
                    </x-ui.badge>
                @endif

                @if ($page->ai_generated)
                    <x-ui.badge color="purple" size="sm" icon="icon.sparkles">
                        {{ __('Drafted by AI') }}
                    </x-ui.badge>
                @endif
            </div>

            {{-- -------------------------------------------------------- --}}
            {{-- Body                                                      --}}
            {{-- -------------------------------------------------------- --}}
            @if ($editing)
                <div class="mt-5 rounded-lg border border-[var(--line-DEFAULT)] bg-[var(--surface-panel)]
                            shadow-xs focus-within:border-[var(--accent)]"
                     x-data="richEditor(@js($content), @js(__('Write the page. Use headings, lists and links.')))"
                     x-on:editor-input="$wire.set('content', $event.detail.html, false)"
                     wire:ignore>

                    <div class="flex flex-wrap items-center gap-0.5 border-b border-[var(--line-subtle)] p-1">
                        @foreach ([
                            ['toggleBold', __('Bold'), 'bold', 'B', 'font-bold'],
                            ['toggleItalic', __('Italic'), 'italic', 'I', 'italic'],
                            ['toggleStrike', __('Strikethrough'), 'strike', 'S', 'line-through'],
                            ['toggleCode', __('Inline code'), 'code', '&lt;/&gt;', 'font-mono text-[10px]'],
                        ] as [$command, $label, $mark, $glyph, $glyphClass])
                            <button type="button" x-on:click="run('{{ $command }}')"
                                    x-bind:class="isActive('{{ $mark }}') && 'bg-[var(--surface-active)] text-[var(--text-strong)]'"
                                    class="grid size-7 place-items-center rounded text-sm text-[var(--text-muted)]
                                           transition-colors hover:bg-[var(--surface-hover)]"
                                    title="{{ $label }}" aria-label="{{ $label }}">
                                <span class="{{ $glyphClass }}">{!! $glyph !!}</span>
                            </button>
                        @endforeach

                        <span class="mx-1 h-4 w-px bg-[var(--line-subtle)]" aria-hidden="true"></span>

                        @foreach ([1, 2, 3] as $level)
                            <button type="button" x-on:click="run('toggleHeading', { level: {{ $level }} })"
                                    x-bind:class="isActive('heading', { level: {{ $level }} }) && 'bg-[var(--surface-active)] text-[var(--text-strong)]'"
                                    class="grid size-7 place-items-center rounded text-xs font-semibold
                                           text-[var(--text-muted)] transition-colors hover:bg-[var(--surface-hover)]"
                                    title="{{ __('Heading :level', ['level' => $level]) }}"
                                    aria-label="{{ __('Heading :level', ['level' => $level]) }}">
                                H{{ $level }}
                            </button>
                        @endforeach

                        <span class="mx-1 h-4 w-px bg-[var(--line-subtle)]" aria-hidden="true"></span>

                        @foreach ([
                            ['toggleBulletList', __('Bulleted list'), 'bulletList', '•'],
                            ['toggleOrderedList', __('Numbered list'), 'orderedList', '1.'],
                            ['toggleTaskList', __('Checklist'), 'taskList', '☑'],
                            ['toggleBlockquote', __('Quote'), 'blockquote', '❝'],
                            ['toggleCodeBlock', __('Code block'), 'codeBlock', '{ }'],
                        ] as [$command, $label, $mark, $glyph])
                            {{-- `dir="ltr"`: the numbered-list glyph is "1." and renders ".1"
                                 when it takes an Arabic page's direction. --}}
                            <button type="button" x-on:click="run('{{ $command }}')"
                                    x-bind:class="isActive('{{ $mark }}') && 'bg-[var(--surface-active)] text-[var(--text-strong)]'"
                                    class="grid h-7 min-w-7 place-items-center rounded px-1 text-xs
                                           text-[var(--text-muted)] transition-colors hover:bg-[var(--surface-hover)]"
                                    dir="ltr"
                                    title="{{ $label }}" aria-label="{{ $label }}">
                                {{ $glyph }}
                            </button>
                        @endforeach
                    </div>

                    <div x-ref="editor" class="min-h-72"></div>
                </div>

                @error('content')
                    <p class="mt-1 text-xs text-critical-600" role="alert">{{ $message }}</p>
                @enderror

                <p class="mt-2 flex items-center gap-1.5 text-2xs text-[var(--text-subtle)]">
                    <x-icon.shield class="size-3.5" />
                    {{ __('Formatting is cleaned on the server before it is stored.') }}
                </p>
            @else
                @if (filled($page->content))
                    {{-- Written by a person; its direction is the text's, not the reader's. --}}
                    <div dir="auto" class="prose-planvio mt-5 text-[0.9375rem]">{!! $page->content !!}</div>
                @else
                    <div class="mt-5 rounded-lg border border-dashed border-[var(--line-DEFAULT)] px-6 py-10 text-center">
                        <p class="text-sm text-[var(--text-muted)]">{{ __('This page is empty.') }}</p>
                        @if ($this->canEdit())
                            <x-ui.button variant="secondary" size="sm" class="mt-3" wire:click="startEditing">
                                {{ __('Start writing') }}
                            </x-ui.button>
                        @endif
                    </div>
                @endif
            @endif

            {{-- -------------------------------------------------------- --}}
            {{-- Attachments                                               --}}
            {{-- -------------------------------------------------------- --}}
            <section class="mt-8 border-t border-[var(--line-subtle)] pt-5" aria-labelledby="wiki-attachments">
                <div class="flex items-center justify-between gap-2">
                    <h2 id="wiki-attachments" class="flex items-center gap-1.5 text-xs font-semibold
                               uppercase tracking-wide text-[var(--text-subtle)]">
                        <x-icon.paperclip class="size-3.5" />
                        {{ __('Attachments') }}
                        <span class="tabular-nums">({{ $this->attachments->count() }})</span>
                    </h2>

                    @can('create', [\App\Models\Attachment::class, $page])
                        <div x-data="{ uploading: false, progress: 0 }"
                             x-on:livewire-upload-start="uploading = true; progress = 0"
                             x-on:livewire-upload-finish="uploading = false"
                             x-on:livewire-upload-cancel="uploading = false"
                             x-on:livewire-upload-error="uploading = false"
                             x-on:livewire-upload-progress="progress = $event.detail.progress"
                             class="flex items-center gap-2">

                            {{-- Driven by the browser's own upload events, so the bar is the
                                 real transfer rather than a server round trip. --}}
                            <div x-show="uploading" x-cloak class="w-28" role="progressbar"
                                 aria-label="{{ __('Uploading') }}" x-bind:aria-valuenow="progress"
                                 aria-valuemin="0" aria-valuemax="100">
                                <div class="h-1.5 overflow-hidden rounded-full bg-[var(--surface-active)]">
                                    <div class="h-full rounded-full bg-[var(--accent)] transition-[width] duration-150"
                                         x-bind:style="`width: ${progress}%`"></div>
                                </div>
                            </div>

                            <label class="inline-flex h-7 cursor-pointer items-center gap-1.5 rounded-md border
                                          border-[var(--line-DEFAULT)] bg-[var(--surface-panel)] px-2.5 text-xs
                                          font-medium text-[var(--text-DEFAULT)] shadow-xs transition-colors
                                          hover:bg-[var(--surface-hover)]">
                                <x-icon.paperclip class="size-3.5" />
                                {{ __('Attach file') }}
                                <input type="file" wire:model="upload" class="sr-only">
                            </label>
                        </div>
                    @endcan
                </div>

                @if ($this->attachments->isEmpty())
                    <p class="mt-3 text-xs text-[var(--text-muted)]">
                        {{ __('Nothing attached. Specs, exports and screenshots that belong with this page go here.') }}
                    </p>
                @else
                    <ul class="mt-3 divide-y divide-[var(--line-subtle)] rounded-lg border border-[var(--line-subtle)]">
                        @foreach ($this->attachments as $attachment)
                            <li class="flex items-center gap-3 px-3 py-2"
                                wire:key="wiki-file-{{ $attachment->getKey() }}">
                                <span class="grid size-8 shrink-0 place-items-center overflow-hidden rounded
                                             bg-[var(--surface-sunken)] text-[var(--text-subtle)]">
                                    @if ($attachment->is_image)
                                        <img src="{{ route('attachments.download', $attachment) }}" alt=""
                                             class="size-full object-cover" loading="lazy">
                                    @else
                                        <span class="text-[9px] font-semibold uppercase">
                                            {{ \Illuminate\Support\Str::limit($attachment->extension, 4, '') }}
                                        </span>
                                    @endif
                                </span>

                                <a href="{{ route('attachments.download', $attachment) }}"
                                   class="min-w-0 flex-1 text-sm text-[var(--text-DEFAULT)] hover:text-[var(--accent)]">
                                    <span class="block truncate">{{ Bidi::numbers($attachment->original_name) }}</span>
                                    <span class="block text-2xs text-[var(--text-subtle)]">
                                        <x-ui.bidi>{{ $attachment->human_size }}</x-ui.bidi>
                                        <span aria-hidden="true">&middot;</span>
                                        {{ $attachment->uploader?->name ?? __('Planvio') }}
                                    </span>
                                </a>

                                @can('delete', $attachment)
                                    <x-ui.button variant="ghost" size="sm" icon-only
                                                 wire:click="removeAttachment({{ $attachment->getKey() }})"
                                                 wire:confirm="{{ __('Remove “:name”?', ['name' => $attachment->original_name]) }}"
                                                 :aria-label="__('Remove :name', ['name' => $attachment->original_name])">
                                        <x-icon.trash class="size-4" />
                                    </x-ui.button>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </article>
    </div>
</div>
