{{--
    The document rail. Shared by the wiki index and a single page so the two read as one
    place: the same tree, in the same order, with the same actions on it.

    Expects: $workspace, $project, $activeId (nullable)
--}}
@php
    $canManage = auth()->user()->can('create', [\App\Models\WikiPage::class, $project]);
    $roots = $this->tree->get('root', collect());
@endphp

<aside x-bind:class="railOpen ? '' : 'max-lg:hidden'"
       class="flex w-full shrink-0 flex-col border-b border-[var(--line-subtle)] bg-[var(--surface-panel)]
              max-lg:max-h-[55vh] lg:w-64 lg:border-b-0 lg:border-e xl:w-72"
       aria-label="{{ __('Documents') }}">

    <div class="flex h-11 shrink-0 items-center gap-1 border-b border-[var(--line-subtle)] px-2 ps-3">
        <h2 class="min-w-0 flex-1 truncate text-xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">
            {{ __('Documents') }}
        </h2>
        <span class="rounded bg-[var(--surface-sunken)] px-1.5 text-2xs font-medium tabular-nums text-[var(--text-muted)]">
            {{ $this->pageCount }}
        </span>
        @if ($canManage)
            <x-ui.tooltip :label="__('New page')">
                <x-ui.button variant="ghost" size="sm" icon-only wire:click="startCreate"
                             :aria-label="__('New page')">
                    <x-icon.plus class="size-4" />
                </x-ui.button>
            </x-ui.tooltip>
        @endif
    </div>

    <div class="shrink-0 px-2 py-2">
        <x-ui.input size="sm" icon="icon.search" type="search"
                    wire:model.live.debounce.300ms="treeSearch"
                    :placeholder="__('Filter documents')"
                    :aria-label="__('Filter documents')" />
    </div>

    <nav class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-1.5 pb-3"
         aria-label="{{ __('Document tree') }}">
        @if ($roots->isEmpty())
            @if (filled($this->treeSearch))
                <p class="px-2 py-6 text-center text-xs text-[var(--text-muted)]">
                    {{ __('No document matches “:term”.', ['term' => $this->treeSearch]) }}
                </p>
            @else
                <p class="px-2 py-6 text-center text-xs text-[var(--text-muted)]">
                    {{ __('No documents yet.') }}
                </p>
            @endif
        @else
            @include('livewire.app.projects._wiki-tree', [
                'nodes' => $roots,
                'tree' => $this->tree,
                'depth' => 0,
                'activeId' => $activeId,
                'canManage' => $canManage,
                'workspace' => $workspace,
                'project' => $project,
                'parentOptions' => $this->parentOptions,
            ])
        @endif
    </nav>
</aside>

{{-- Creation is a dialog rather than a page: you are always making a page *from* somewhere. --}}
<x-ui.modal wire:model="showCreate" :title="__('New document')" size="md">
    <form wire:submit="createPage" class="space-y-4">
        <x-ui.field :label="__('Title')" for="wiki-new-title" :error="$errors->first('newTitle')" required>
            <x-ui.input id="wiki-new-title" wire:model="newTitle" size="lg" maxlength="255"
                        :placeholder="__('Project brief, Runbook, Decision log')"
                        :invalid="$errors->has('newTitle')" autofocus />
        </x-ui.field>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Location')" for="wiki-new-parent"
                        :hint="__('Where it sits in the tree.')">
                <x-ui.select id="wiki-new-parent" wire:model="newParentId">
                    <option value="">{{ __('Top level') }}</option>
                    @foreach ($this->parentOptions as $optionId => $optionLabel)
                        <option value="{{ $optionId }}">{{ $optionLabel }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field :label="__('Who can read it')" for="wiki-new-visibility"
                        :error="$errors->first('newVisibility')">
                <x-ui.select id="wiki-new-visibility" wire:model="newVisibility"
                             :invalid="$errors->has('newVisibility')">
                    @foreach (\App\Enums\WikiVisibility::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </div>
    </form>

    <x-slot:footer>
        <x-ui.button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
        <x-ui.button variant="primary" wire:click="createPage" wire:target="createPage">
            {{ __('Create page') }}
        </x-ui.button>
    </x-slot:footer>
</x-ui.modal>
