@php
    use App\Services\Import\CsvSource;
    use App\Services\Import\ImportProgress;
    use App\Support\Formats;

    $steps = $this->steps();
    $missing = $this->path === '' ? [] : $this->missingRequired();
    $delimiterLabel = $this->delimiterLabel();
@endphp

<div class="page py-5">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Header                                                           --}}
    {{-- ---------------------------------------------------------------- --}}
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-2xs font-semibold uppercase tracking-widest text-[var(--text-subtle)]">
                {{ $project->name }}
            </p>
            <h1 class="mt-0.5 text-base font-semibold tracking-tight text-[var(--text-strong)]">
                {{ __('Import tasks from a CSV') }}
            </h1>
            <p class="mt-0.5 max-w-2xl text-xs text-[var(--text-muted)]">
                {{ __('Every row is created the same way a person creates a task: same validation, same activity entry, same notifications.') }}
            </p>
        </div>

        <div class="flex items-center gap-1.5">
            @if ($path !== '' && ! $this->isRunning())
                <x-ui.button variant="ghost" size="md" wire:click="startOver">{{ __('Start over') }}</x-ui.button>
            @endif
            <x-ui.button variant="secondary" size="md"
                         :href="route('app.projects.tasks', [$workspace, $project])">
                {{ __('Back to tasks') }}
            </x-ui.button>
        </div>
    </header>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Step rail                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <ol class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-5" aria-label="{{ __('Import steps') }}">
        @foreach ($steps as $item)
            @php
                $done = $item['number'] < $step;
                $current = $item['number'] === $step;
            @endphp
            <li>
                <button type="button"
                        wire:click="goTo({{ $item['number'] }})"
                        wire:key="import-step-{{ $item['number'] }}"
                        {{-- Once the import has started, the rail is a record of where it went,
                             not a control. A button that looks live and does nothing is worse
                             than one that is visibly spent. --}}
                        @disabled($item['number'] > $step || ($step === 5 && $token !== ''))
                        aria-current="{{ $current ? 'step' : 'false' }}"
                        class="w-full rounded-lg border px-2.5 py-2 text-start transition-colors
                               disabled:cursor-default disabled:opacity-60
                               {{ $current
                                    ? 'border-[var(--accent)] bg-[var(--accent-soft)]'
                                    : ($done
                                        ? 'border-[var(--line-subtle)] bg-[var(--surface-panel)] hover:border-[var(--line-strong)]'
                                        : 'border-dashed border-[var(--line-subtle)] bg-transparent') }}">
                    <span class="flex items-center gap-1.5">
                        <span class="grid size-4.5 shrink-0 place-items-center rounded-full text-[10px] font-semibold
                                     {{ $done
                                        ? 'bg-positive-500 text-white'
                                        : ($current ? 'bg-[var(--accent)] text-white' : 'bg-[var(--surface-active)] text-[var(--text-subtle)]') }}">
                            {{ $done ? '✓' : $item['number'] }}
                        </span>
                        <span class="truncate text-xs font-medium text-[var(--text-strong)]">{{ $item['label'] }}</span>
                    </span>
                    <span class="mt-0.5 block truncate text-2xs text-[var(--text-muted)]">{{ $item['hint'] }}</span>
                </button>
            </li>
        @endforeach
    </ol>

    <div class="mt-4">

        {{-- ============================================================ --}}
        {{-- 1 · Upload                                                   --}}
        {{-- ============================================================ --}}
        @if ($step === 1)
            <x-ui.card>
                <div x-data="{ over: false }"
                     x-on:dragover.prevent="over = true"
                     x-on:dragleave.prevent="over = false"
                     x-on:drop="over = false"
                     class="rounded-lg border-2 border-dashed p-8 text-center transition-colors"
                     x-bind:class="over ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-[var(--line-DEFAULT)]'">

                    <div wire:loading.delay wire:target="file" class="flex flex-col items-center gap-2">
                        <x-ui.spinner class="size-6 text-[var(--accent)]" />
                        <p class="text-sm text-[var(--text-muted)]">{{ __('Reading the file…') }}</p>
                    </div>

                    <div wire:loading.delay.remove wire:target="file">
                        <div class="mx-auto grid size-11 place-items-center rounded-xl border border-[var(--line-subtle)]
                                    bg-[var(--surface-sunken)]">
                            <x-icon.document class="size-5 text-[var(--text-subtle)]" />
                        </div>

                        <h2 class="mt-3 text-[0.9375rem] font-semibold text-[var(--text-strong)]">
                            {{ __('Drop a CSV here') }}
                        </h2>
                        <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-[var(--text-muted)]">
                            {{ __('Exported from a spreadsheet, from another tracker, from anywhere. Planvio works out the separator and reads the first row as the column names.') }}
                        </p>

                        <label class="mt-4 inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md border border-transparent
                                      bg-[var(--accent)] px-3 text-sm font-medium text-white shadow-xs
                                      transition-[background-color] hover:bg-[var(--accent-hover)]
                                      focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-[var(--accent)]">
                            <x-icon.plus class="size-4" />
                            {{ __('Choose a file') }}
                            <input type="file" wire:model="file" accept=".csv,.tsv,.txt,text/csv" class="sr-only">
                        </label>

                        <p class="mt-3 text-2xs text-[var(--text-subtle)]">
                            {{ __('CSV, TSV or a plain text file, up to 8 MB and :rows rows.', ['rows' => Formats::number(CsvSource::MAX_ROWS)]) }}
                            ·
                            <button type="button" wire:click="template"
                                    class="underline underline-offset-2 hover:text-[var(--text-DEFAULT)]">
                                {{ __('Download a template') }}
                            </button>
                        </p>
                    </div>
                </div>

                @error('file')
                    <p class="mt-3 flex items-start gap-1.5 rounded-md border border-critical-500/40 bg-critical-50 px-3 py-2
                              text-xs text-critical-700 dark:bg-critical-950 dark:text-critical-100" role="alert">
                        <x-icon.warning class="mt-0.5 size-3.5 shrink-0" />
                        <span>{{ $message }}</span>
                    </p>
                @enderror

                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    @foreach ([
                        [__('It is read as text'), __('A cell starting with = or + is a formula in a spreadsheet. Planvio never evaluates one — on import it is text, and on export it is written so your spreadsheet will not run it either.')],
                        [__('Nothing is guessed'), __('An ambiguous date like 03/04/2026 is reported rather than picked. An unknown assignee imports unassigned and says so.')],
                        [__('It cannot be undone'), __('Imported tasks are ordinary tasks. There is no bulk undo, which is why steps 3 and 4 exist.')],
                    ] as [$title, $body])
                        <div class="rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-sunken)] p-3">
                            <h3 class="text-xs font-semibold text-[var(--text-strong)]">{{ $title }}</h3>
                            <p class="mt-1 text-2xs leading-relaxed text-[var(--text-muted)]">{{ $body }}</p>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        @endif

        {{-- ============================================================ --}}
        {{-- 2 · Map columns                                              --}}
        {{-- ============================================================ --}}
        @if ($step === 2)
            <x-ui.card :title="__('Map the columns')">
                <x-slot:subtitle>
                    {{ $originalName }} · {{ trans_choice('{1}:count row|[2,*]:count rows', $rowCount, ['count' => Formats::number($rowCount)]) }}
                    · {{ trans_choice('{1}:count column|[2,*]:count columns', count($headers), ['count' => count($headers)]) }}
                    · {{ __('separated by :delimiter', ['delimiter' => mb_strtolower($delimiterLabel)]) }}
                </x-slot:subtitle>

                <x-slot:actions>
                    <div class="flex items-center gap-1.5">
                        <x-ui.button size="sm" variant="ghost" wire:click="clearMapping">{{ __('Clear') }}</x-ui.button>
                        <x-ui.button size="sm" variant="secondary" wire:click="autoMap">{{ __('Guess again') }}</x-ui.button>
                    </div>
                </x-slot:actions>

                @if ($truncated)
                    <p class="mb-3 flex items-start gap-1.5 rounded-md border border-caution-500/40 bg-caution-50 px-3 py-2
                              text-xs text-caution-700 dark:bg-caution-950 dark:text-caution-100">
                        <x-icon.warning class="mt-0.5 size-3.5 shrink-0" />
                        <span>{{ __('This file is longer than Planvio imports in one go. The first :rows rows will be read; split the rest into another file.', ['rows' => Formats::number(CsvSource::MAX_ROWS)]) }}</span>
                    </p>
                @endif

                <div class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    @foreach ($this->fields() as $field)
                        <div wire:key="map-{{ $field->value }}">
                            <label for="map-{{ $field->value }}"
                                   class="flex items-center gap-1.5 text-xs font-medium text-[var(--text-DEFAULT)]">
                                {{ $field->label() }}
                                @if ($field->isRequired())
                                    <span class="text-critical-600" aria-hidden="true">*</span>
                                    <span class="sr-only">{{ __('required') }}</span>
                                @endif
                            </label>
                            <x-ui.select id="map-{{ $field->value }}" size="md" class="mt-1"
                                         wire:model.live="mapping.{{ $field->value }}"
                                         :options="$this->columnOptions"
                                         :invalid="$field->isRequired() && in_array($field, $missing, true)" />
                            <p class="mt-1 text-2xs leading-relaxed text-[var(--text-muted)]">{{ $field->hint() }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 border-t border-[var(--line-subtle)] pt-3">
                    <x-ui.checkbox wire:model.live="createMissingTags"
                                   :label="__('Create tags this file names but the workspace does not have')"
                                   :description="__('Off by default. A misspelt tag in one row becomes a permanent label everyone sees in the picker.')" />
                </div>

                @if ($missing !== [])
                    <p class="mt-3 flex items-start gap-1.5 text-xs text-critical-600" role="alert">
                        <x-icon.warning class="mt-0.5 size-3.5 shrink-0" />
                        <span>{{ __('Point :fields at a column before continuing.', [
                            'fields' => implode(', ', array_map(fn ($field) => $field->label(), $missing)),
                        ]) }}</span>
                    </p>
                @endif
            </x-ui.card>
        @endif

        {{-- ============================================================ --}}
        {{-- 3 · Validate                                                 --}}
        {{-- ============================================================ --}}
        @if ($step === 3)
            @php $report = $this->report; @endphp

            <x-ui.card :title="__('What the file contains')" flush>
                <div class="grid grid-cols-2 gap-px border-b border-[var(--line-subtle)] bg-[var(--line-subtle)] sm:grid-cols-4">
                    @foreach ([
                        [__('Rows read'), Formats::number($report->rows), 'neutral'],
                        [__('Will import'), Formats::number($report->importable), $report->importable > 0 ? 'positive' : 'neutral'],
                        [__('Blocked'), Formats::number($report->blocked), $report->blocked > 0 ? 'critical' : 'neutral'],
                        [__('With warnings'), Formats::number($report->rowsWithWarnings), $report->rowsWithWarnings > 0 ? 'caution' : 'neutral'],
                    ] as [$label, $value, $tone])
                        <div class="bg-[var(--surface-panel)] px-4 py-3">
                            <p class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ $label }}</p>
                            <p class="mt-0.5 text-lg font-semibold tabular-nums
                                      {{ match ($tone) {
                                            'positive' => 'text-positive-600 dark:text-positive-500',
                                            'critical' => 'text-critical-600 dark:text-critical-500',
                                            'caution' => 'text-caution-700 dark:text-caution-500',
                                            default => 'text-[var(--text-strong)]',
                                      } }}">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2 text-xs text-[var(--text-muted)]">
                        <x-ui.badge color="red" size="sm">{{ __('Error') }}</x-ui.badge>
                        <span>{{ __('the row is skipped') }}</span>
                        <span class="text-[var(--text-subtle)]">·</span>
                        <x-ui.badge color="amber" size="sm">{{ __('Warning') }}</x-ui.badge>
                        <span>{{ __('the task is created without that one detail') }}</span>
                    </div>
                </div>

                @if ($report->issues === [])
                    <x-ui.empty-state icon="icon.check-circle" compact
                                      :title="__('Every row checks out')"
                                      :description="__('No missing titles, no unreadable dates, no names Planvio could not place. Have a look at the preview and import it.')" />
                @else
                    <div class="max-h-96 overflow-y-auto scrollbar-thin border-t border-[var(--line-subtle)]">
                        <table class="w-full text-xs">
                            <thead class="sticky top-0 bg-[var(--surface-panel)]">
                                <tr class="border-b border-[var(--line-subtle)] text-start text-2xs uppercase tracking-wider text-[var(--text-muted)]">
                                    <th scope="col" class="w-16 px-4 py-2 font-semibold">{{ __('Row') }}</th>
                                    <th scope="col" class="w-24 px-3 py-2 font-semibold">{{ __('Severity') }}</th>
                                    <th scope="col" class="w-28 px-3 py-2 font-semibold">{{ __('Field') }}</th>
                                    <th scope="col" class="px-3 py-2 font-semibold">{{ __('Value') }}</th>
                                    <th scope="col" class="px-4 py-2 font-semibold">{{ __('What happens') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--line-subtle)]">
                                @foreach ($report->issues as $index => $issue)
                                    <tr wire:key="issue-{{ $index }}">
                                        <td class="px-4 py-1.5 tabular-nums text-[var(--text-muted)]">{{ $issue->row }}</td>
                                        <td class="px-3 py-1.5">
                                            <x-ui.badge :color="$issue->severity->color()" size="sm">
                                                {{ $issue->severity->label() }}
                                            </x-ui.badge>
                                        </td>
                                        <td class="px-3 py-1.5 text-[var(--text-muted)]">{{ $issue->field?->label() ?? '—' }}</td>
                                        <td class="max-w-48 truncate px-3 py-1.5 font-mono text-2xs text-[var(--text-DEFAULT)]">
                                            {{ $issue->value ?? '—' }}
                                        </td>
                                        <td class="px-4 py-1.5 text-[var(--text-DEFAULT)]">{{ $issue->message }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($report->issuesTruncated)
                        <p class="border-t border-[var(--line-subtle)] px-4 py-2 text-2xs text-[var(--text-subtle)]">
                            {{ __('Showing the first :count problems. The counts above cover the whole file.', ['count' => count($report->issues)]) }}
                        </p>
                    @endif
                @endif
            </x-ui.card>
        @endif

        {{-- ============================================================ --}}
        {{-- 4 · Preview                                                  --}}
        {{-- ============================================================ --}}
        @if ($step === 4)
            @php $rows = $this->previewRows; @endphp

            <x-ui.card :title="__('The first rows, as they will be created')" flush>
                <x-slot:subtitle>
                    {{ __('Blocked rows are shown struck through: they will be skipped, not created.') }}
                </x-slot:subtitle>

                @if ($rows === [])
                    <x-ui.empty-state icon="icon.list" compact
                                      :title="__('Nothing to preview')"
                                      :description="__('Every row in the file was empty once the mapping was applied.')" />
                @else
                    <div class="overflow-x-auto scrollbar-thin">
                        <table class="w-full min-w-max text-xs">
                            <thead>
                                <tr class="border-b border-[var(--line-subtle)] text-start text-2xs uppercase tracking-wider text-[var(--text-muted)]">
                                    <th scope="col" class="w-12 px-4 py-2 font-semibold">{{ __('Row') }}</th>
                                    <th scope="col" class="px-3 py-2 font-semibold">{{ __('Title') }}</th>
                                    <th scope="col" class="px-3 py-2 font-semibold">{{ __('Status') }}</th>
                                    <th scope="col" class="px-3 py-2 font-semibold">{{ __('Priority') }}</th>
                                    <th scope="col" class="px-3 py-2 font-semibold">{{ __('Assignee') }}</th>
                                    <th scope="col" class="px-3 py-2 font-semibold">{{ __('Start') }}</th>
                                    <th scope="col" class="px-3 py-2 font-semibold">{{ __('Due') }}</th>
                                    <th scope="col" class="px-3 py-2 font-semibold">{{ __('Estimate') }}</th>
                                    <th scope="col" class="px-3 py-2 font-semibold">{{ __('Milestone') }}</th>
                                    <th scope="col" class="px-4 py-2 font-semibold">{{ __('Tags') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--line-subtle)]">
                                @foreach ($rows as $row)
                                    <tr wire:key="preview-{{ $row->row }}"
                                        class="{{ $row->hasErrors() ? 'bg-critical-50/60 dark:bg-critical-950/40' : '' }}">
                                        <td class="px-4 py-1.5 tabular-nums text-[var(--text-subtle)]">{{ $row->row }}</td>
                                        <td class="max-w-72 truncate px-3 py-1.5 font-medium
                                                   {{ $row->hasErrors() ? 'text-[var(--text-muted)] line-through' : 'text-[var(--text-strong)]' }}">
                                            {{ $row->title !== '' ? $row->title : __('(no title)') }}
                                        </td>
                                        <td class="px-3 py-1.5">
                                            @if ($row->status)
                                                <x-ui.badge :color="$row->status->color ?? 'gray'" size="sm" dot>{{ $row->status->name }}</x-ui.badge>
                                            @else
                                                <span class="text-[var(--text-subtle)]">—</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-1.5">
                                            <x-ui.badge :color="$row->priority->color()" size="sm">{{ $row->priority->label() }}</x-ui.badge>
                                        </td>
                                        <td class="px-3 py-1.5 text-[var(--text-DEFAULT)]">
                                            {{ $row->assignee?->name ?? __('Unassigned') }}
                                        </td>
                                        <td class="px-3 py-1.5 tabular-nums text-[var(--text-muted)]">
                                            {{ $row->startDate?->toDateString() ?? '—' }}
                                        </td>
                                        <td class="px-3 py-1.5 tabular-nums text-[var(--text-muted)]">
                                            {{ $row->dueDate?->toDateString() ?? '—' }}
                                        </td>
                                        <td class="px-3 py-1.5 tabular-nums text-[var(--text-muted)]">
                                            {{ $row->estimateLabel() ?? '—' }}
                                        </td>
                                        <td class="max-w-40 truncate px-3 py-1.5 text-[var(--text-muted)]">
                                            {{ $row->milestone?->name ?? '—' }}
                                        </td>
                                        <td class="px-4 py-1.5">
                                            @if ($row->tagNames() === [])
                                                <span class="text-[var(--text-subtle)]">—</span>
                                            @else
                                                <span class="flex flex-wrap gap-1">
                                                    @foreach ($row->tagNames() as $name)
                                                        <x-ui.badge color="gray" size="sm">{{ $name }}</x-ui.badge>
                                                    @endforeach
                                                </span>
                                            @endif
                                        </td>
                                    </tr>

                                    @if ($row->issues !== [])
                                        <tr wire:key="preview-issues-{{ $row->row }}" class="bg-[var(--surface-sunken)]">
                                            <td></td>
                                            <td colspan="9" class="px-3 pb-2 pt-0">
                                                <ul class="space-y-0.5">
                                                    @foreach ($row->issues as $issue)
                                                        <li class="flex items-start gap-1.5 text-2xs">
                                                            <span class="mt-1 size-1.5 shrink-0 rounded-full
                                                                         {{ $issue->blocks() ? 'bg-critical-500' : 'bg-caution-500' }}"
                                                                  aria-hidden="true"></span>
                                                            <span class="text-[var(--text-muted)]">
                                                                <span class="font-medium text-[var(--text-DEFAULT)]">{{ $issue->field?->label() }}</span>
                                                                @if ($issue->value)
                                                                    <span class="font-mono">“{{ $issue->value }}”</span>
                                                                @endif
                                                                — {{ $issue->message }}
                                                            </span>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($rowCount > count($rows))
                        <p class="border-t border-[var(--line-subtle)] px-4 py-2 text-2xs text-[var(--text-subtle)]">
                            {{ __('Showing :shown of :total rows.', ['shown' => count($rows), 'total' => Formats::number($rowCount)]) }}
                        </p>
                    @endif
                @endif
            </x-ui.card>
        @endif

        {{-- ============================================================ --}}
        {{-- 5 · Import                                                   --}}
        {{-- ============================================================ --}}
        @if ($step === 5)
            @php $progress = $this->progress; @endphp

            @if ($progress === null)
                @php $report = $this->report; @endphp

                <x-ui.card :title="__('Ready to import')">
                    <p class="text-sm text-[var(--text-DEFAULT)]">
                        {{ trans_choice(
                            '{1}One task will be created in :project.|[2,*]:count tasks will be created in :project.',
                            $report->importable,
                            ['count' => Formats::number($report->importable), 'project' => $project->name],
                        ) }}
                        @if ($report->blocked > 0)
                            {{ trans_choice(
                                '{1}One row will be skipped because it has an error.|[2,*]:count rows will be skipped because they have errors.',
                                $report->blocked,
                                ['count' => Formats::number($report->blocked)],
                            ) }}
                        @endif
                    </p>

                    <div class="mt-4 rounded-lg border border-caution-500/40 bg-caution-50 p-3 dark:bg-caution-950">
                        <h3 class="flex items-center gap-1.5 text-xs font-semibold text-caution-700 dark:text-caution-100">
                            <x-icon.warning class="size-3.5" />
                            {{ __('Read this before you press the button') }}
                        </h3>
                        <ul class="mt-1.5 space-y-1 text-xs leading-relaxed text-caution-700 dark:text-caution-100">
                            <li>{{ __('Rows are written one at a time and are not rolled back together. If row 400 fails, rows 1 to 399 stay imported — you will be told which rows failed and why.') }}</li>
                            <li>{{ __('There is no bulk undo. Imported tasks are ordinary tasks and would have to be deleted individually.') }}</li>
                            <li>{{ __('Everyone assigned or watching will be notified, exactly as they would be for a task created by hand.') }}</li>
                            @if ($this->willQueue())
                                <li>{{ __('This file is large enough to run in the background. You can leave this page; the tasks will keep appearing.') }}</li>
                            @endif
                        </ul>
                    </div>

                    <div class="mt-3">
                        <x-ui.checkbox wire:model.live="acknowledged"
                                       :label="__('I understand this cannot be undone in one step.')" />
                    </div>

                    <x-slot:footer>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-2xs text-[var(--text-subtle)]">
                                {{ __('Importing as :name.', ['name' => auth()->user()?->name]) }}
                            </p>
                            <x-ui.button variant="primary" size="md" icon="icon.plus"
                                         wire:click="import"
                                         wire:loading.attr="disabled"
                                         wire:target="import"
                                         :disabled="! $acknowledged || ! $report->canImport()">
                                {{ $this->willQueue() ? __('Start the import') : __('Import now') }}
                            </x-ui.button>
                        </div>
                    </x-slot:footer>
                </x-ui.card>
            @else
                {{--
                    The poll lives on a wrapper rather than on the card, so it can be
                    conditional: a finished import must stop asking, and an attribute added
                    with a directive inside a component tag is not a thing Blade compiles.
                --}}
                <div @if ($progress->isRunning()) wire:poll.1500ms="refreshProgress" @endif>
                <x-ui.card :title="$progress->isRunning() ? __('Importing…') : ($progress->status === ImportProgress::FAILED ? __('The import stopped') : __('Import finished'))">

                    <x-ui.progress :value="$progress->percentage()" size="lg" show-label
                                   :label="__('Import progress')" />

                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        @foreach ([
                            [__('Rows'), Formats::number($progress->processed).' / '.Formats::number($progress->total), 'neutral'],
                            [__('Created'), Formats::number($progress->created), 'positive'],
                            [__('Skipped'), Formats::number($progress->skipped), $progress->skipped > 0 ? 'caution' : 'neutral'],
                            [__('Failed'), Formats::number($progress->failed), $progress->failed > 0 ? 'critical' : 'neutral'],
                        ] as [$label, $value, $tone])
                            <div class="rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-sunken)] px-3 py-2">
                                <p class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ $label }}</p>
                                <p class="mt-0.5 text-sm font-semibold tabular-nums
                                          {{ match ($tone) {
                                                'positive' => 'text-positive-600 dark:text-positive-500',
                                                'critical' => 'text-critical-600 dark:text-critical-500',
                                                'caution' => 'text-caution-700 dark:text-caution-500',
                                                default => 'text-[var(--text-strong)]',
                                          } }}">{{ $value }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if ($progress->message)
                        <p class="mt-3 flex items-start gap-1.5 rounded-md border border-critical-500/40 bg-critical-50 px-3 py-2
                                  text-xs text-critical-700 dark:bg-critical-950 dark:text-critical-100" role="alert">
                            <x-icon.warning class="mt-0.5 size-3.5 shrink-0" />
                            <span>{{ $progress->message }}</span>
                        </p>
                    @endif

                    @if ($progress->isRunning() && $queued)
                        <p class="mt-3 text-2xs text-[var(--text-subtle)]">
                            {{ __('Running in the background. This page updates itself, and you can close it — the import carries on.') }}
                        </p>
                    @endif

                    @if ($progress->issues !== [])
                        <div class="mt-4 overflow-hidden rounded-lg border border-[var(--line-subtle)]">
                            <p class="border-b border-[var(--line-subtle)] bg-[var(--surface-sunken)] px-3 py-2 text-xs font-medium text-[var(--text-strong)]">
                                {{ __('Rows that did not make it') }}
                            </p>
                            <div class="max-h-72 overflow-y-auto scrollbar-thin">
                                <ul class="divide-y divide-[var(--line-subtle)]">
                                    @foreach ($progress->issues as $index => $issue)
                                        <li class="px-3 py-1.5 text-xs" wire:key="run-issue-{{ $index }}">
                                            <span class="tabular-nums text-[var(--text-subtle)]">{{ __('Row :row', ['row' => $issue->row]) }}</span>
                                            <span class="ms-1.5 text-[var(--text-DEFAULT)]">{{ $issue->message }}</span>
                                            @if ($issue->value)
                                                <span class="ms-1 font-mono text-2xs text-[var(--text-muted)]">“{{ $issue->value }}”</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                            @if ($progress->issuesTruncated)
                                <p class="border-t border-[var(--line-subtle)] px-3 py-1.5 text-2xs text-[var(--text-subtle)]">
                                    {{ __('Showing the first :count.', ['count' => count($progress->issues)]) }}
                                </p>
                            @endif
                        </div>
                    @endif

                    @if ($progress->isDone())
                        <x-slot:footer>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.button variant="primary" size="md"
                                             :href="route('app.projects.tasks', [$workspace, $project])">
                                    {{ __('See the tasks') }}
                                </x-ui.button>
                                <x-ui.button variant="secondary" size="md" wire:click="startOver">
                                    {{ __('Import another file') }}
                                </x-ui.button>
                            </div>
                        </x-slot:footer>
                    @endif
                </x-ui.card>
                </div>
            @endif
        @endif
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Step controls                                                    --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($step > 1 && $step < 5)
        <div class="mt-4 flex items-center justify-between gap-2">
            <x-ui.button variant="secondary" size="md" wire:click="back">{{ __('Back') }}</x-ui.button>

            <div class="flex items-center gap-2">
                <span wire:loading.delay wire:target="next,goTo" class="text-[var(--text-subtle)]">
                    <x-ui.spinner class="size-4" />
                </span>
                <x-ui.button variant="primary" size="md" wire:click="next"
                             :disabled="$step === 2 && $missing !== []">
                    {{ $step === 4 ? __('Continue to import') : __('Continue') }}
                </x-ui.button>
            </div>
        </div>
    @endif
</div>
