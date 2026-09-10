{{--
    The approval card.

    The agent has stopped one step short of changing something and asked a named person to
    take responsibility for the change. So this card is not a summary of the request — it is
    the request. The arguments below are the exact bytes the runner will replay if this is
    approved, unredacted and untruncated, because a parked `ai_tool_runs` row deliberately
    keeps them verbatim (tests/Feature/Security/AiApprovalFidelityTest.php). Showing a
    tidied version here would mean the approver authorised one thing and the system carried
    out another.

    Expects: $toolRun (App\Models\AiToolRun), $indent (string), $showObjective (bool)
--}}
@php
    use App\Support\Bidi;

    $risk = $toolRun->risk ?? \App\Enums\AiToolRisk::Read;
    $arguments = $this->approvalArguments($toolRun);
    $expiresAt = $this->approvalExpiresAt($toolRun);
    $expired = $this->approvalExpired($toolRun);
    $typed = $this->needsTypedConfirmation($toolRun);
    $requester = $toolRun->user ?? $toolRun->run?->user;
    $showObjective = $showObjective ?? false;
    $id = (int) $toolRun->getKey();
@endphp

<div wire:key="approval-{{ $id }}"
     class="{{ $indent }} overflow-hidden rounded-lg border border-caution-500/40 bg-caution-50/60
            shadow-panel dark:border-caution-500/30 dark:bg-caution-500/5">

    {{-- ------------------------------------------------------------------ --}}
    {{-- What is being asked                                                --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="flex flex-wrap items-start gap-x-3 gap-y-2 border-b border-caution-500/25 px-3.5 py-3">
        <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-md bg-caution-100 text-caution-700
                     dark:bg-caution-500/20 dark:text-caution-100">
            <x-icon.shield class="size-4" />
        </span>

        <div class="min-w-0 flex-1">
            <p class="text-2xs font-semibold uppercase tracking-wider text-caution-700 dark:text-caution-500">
                {{ __('Approval required') }}
            </p>
            <p class="mt-0.5 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span class="text-sm font-semibold text-[var(--text-strong)]">
                    {{ \App\Livewire\App\Ai\Support\ToolTrace::humanise((string) $toolRun->tool) }}
                </span>
                <code class="font-mono text-2xs text-[var(--text-muted)]" dir="ltr">{{ $toolRun->tool }}</code>
            </p>
        </div>

        <x-ui.badge :color="$risk->color()" size="sm">{{ $risk->label() }}</x-ui.badge>
    </div>

    <div class="space-y-3 px-3.5 py-3">

        {{-- Who is acting, and under what instruction. --}}
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-2xs text-[var(--text-muted)]">
            @if ($requester)
                <span class="inline-flex items-center gap-1.5">
                    <x-ui.avatar :user="$requester" size="xs" />
                    {{ __('Acting for :name', ['name' => $requester->name]) }}
                </span>
            @endif

            @if ($toolRun->project)
                <span class="inline-flex items-center gap-1.5">
                    <span class="size-1.5 rounded-full" style="background-color: {{ $toolRun->project->color }}"
                          aria-hidden="true"></span>
                    {{ $toolRun->project->name }}
                </span>
            @endif

            @if ($expiresAt)
                <span class="inline-flex items-center gap-1 {{ $expired ? 'text-critical-600 dark:text-critical-500' : '' }}">
                    <x-icon.clock class="size-3.5" />
                    @if ($expired)
                        {{ __('Expired') }}
                    @else
                        {{ __('Expires') }}
                        <span x-data="relativeTime('{{ $expiresAt->toIso8601String() }}')" x-text="label"></span>
                    @endif
                </span>
            @endif
        </div>

        @if ($showObjective && filled($toolRun->run?->objective))
            <p class="rounded-md bg-[var(--surface-panel)] px-2.5 py-2 text-xs leading-relaxed text-[var(--text-muted)]">
                <span class="font-medium text-[var(--text-DEFAULT)]">{{ __('Asked for:') }}</span>
                {{-- Somebody typed this, in a language that is not a property of whoever is
                     reading it now, so it resolves its own direction. --}}
                <span dir="auto" class="bidi-isolate">{{
                    Bidi::numbers(\Illuminate\Support\Str::limit((string) $toolRun->run->objective, 220))
                }}</span>
            </p>
        @endif

        {{-- ---------------------------------------------------------------- --}}
        {{-- The arguments, exactly as they will execute                      --}}
        {{-- ---------------------------------------------------------------- --}}
        <div class="rounded-md border border-[var(--line-subtle)] bg-[var(--surface-panel)]">
            <p class="border-b border-[var(--line-subtle)] px-3 py-1.5 text-2xs font-semibold uppercase
                      tracking-wider text-[var(--text-subtle)]">
                {{ __('Exactly what will run') }}
            </p>

            @if ($arguments === [])
                <p class="px-3 py-2 text-xs text-[var(--text-muted)]">
                    {{ __('This call takes no arguments.') }}
                </p>
            @else
                {{--
                    The one screen where what is on it has to be exactly what will run.

                    A key is a JSON field name and reads left to right whatever the page does.
                    A value is whatever was passed, so it takes its direction from itself —
                    and the dates, times and ratios inside it are isolated individually,
                    because an Arabic word earlier in the same value turns the digits after it
                    into Arabic numbers and hands their separators to the paragraph:
                    `2026-09-02` then renders `02-09-2026`, and somebody is being asked to
                    authorise a deletion on the strength of reading it.
                --}}
                <dl class="divide-y divide-[var(--line-subtle)]">
                    @foreach ($arguments as $argument)
                        <div class="grid gap-0.5 px-3 py-2 {{ $argument['multiline'] ? '' : 'sm:grid-cols-[10rem_1fr] sm:gap-3' }}">
                            <dt class="font-mono text-2xs text-[var(--text-subtle)]"><x-ui.bidi>{{
                                $argument['key']
                            }}</x-ui.bidi></dt>

                            {{--
                                A value that wraps has to resolve its direction at block level:
                                an isolated inline that runs onto a second line has its lines
                                ordered by the block, so an English sentence comes back with
                                its second half above its first. A value that fits on one line
                                keeps the row's own alignment, so that it stays beside the key
                                it belongs to rather than at the far edge of the card.
                            --}}
                            <dd @class([
                                    'whitespace-pre-wrap break-words font-mono text-2xs leading-relaxed',
                                    'text-[var(--text-strong)]',
                                ])
                                @if ($argument['multiline']) dir="auto" @endif>@if ($argument['multiline']){{
                                    Bidi::numbers($argument['value'])
                                }}@else<span dir="auto" class="bidi-isolate">{{
                                    Bidi::numbers($argument['value'])
                                }}</span>@endif</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </div>

        {{--
            The blast radius the tool counted before anything ran
            (App\Ai\Tools\Elevated\ReportsConsequences). Measured facts, not a sentence the
            model wrote about its own intentions.
        --}}
        @if (filled($toolRun->result_summary))
            <div class="rounded-md border border-[var(--line-subtle)] bg-[var(--surface-panel)] px-3 py-2">
                <p class="text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
                    {{ __('What this would affect') }}
                </p>
                <p dir="auto"
                   class="mt-1 text-xs leading-relaxed text-[var(--text-DEFAULT)]">{{
                    Bidi::numbers((string) $toolRun->result_summary)
                }}</p>
            </div>
        @endif

        {{-- ---------------------------------------------------------------- --}}
        {{-- The decision                                                     --}}
        {{-- ---------------------------------------------------------------- --}}
        @if ($expired)
            <p class="text-xs leading-relaxed text-[var(--text-muted)]">
                {{ __('This request is past its expiry, so it can no longer be carried out. It will be closed as rejected the next time approvals are swept.') }}
            </p>
        @else
            @if ($typed)
                {{--
                    A destructive call is the last moment before something stops existing.
                    Typing the tool's own name is the smallest speed bump that cannot be
                    cleared by a stray click on a trackpad.
                --}}
                <div>
                    <label for="confirm-{{ $id }}" class="block text-2xs font-medium text-[var(--text-DEFAULT)]">
                        {{ __('Type :tool to confirm', ['tool' => Bidi::ltr((string) $toolRun->tool)]) }}
                    </label>
                    <x-ui.input id="confirm-{{ $id }}"
                                size="sm"
                                class="mt-1 font-mono"
                                autocomplete="off"
                                spellcheck="false"
                                :placeholder="$toolRun->tool"
                                wire:model="approvalConfirmations.{{ $id }}"
                                :invalid="$errors->has('approval.'.$id)" />
                    @error('approval.'.$id)
                        <p class="mt-1 text-2xs text-critical-600 dark:text-critical-500">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-2">
                <x-ui.input size="sm"
                            class="min-w-0 flex-1"
                            maxlength="240"
                            :placeholder="__('Reason, if you are refusing (optional)')"
                            :aria-label="__('Reason for rejecting')"
                            wire:model="rejectionReasons.{{ $id }}" />

                {{-- One click. Refusing is always the safe outcome; nobody works for it. --}}
                <x-ui.button size="sm" variant="secondary" wire:click="rejectToolRun({{ $id }})">
                    {{ __('Reject') }}
                </x-ui.button>

                {{--
                    No browser confirm on the destructive path: the typed field above *is*
                    the confirmation, and stacking a second dialog on it only teaches people
                    to dismiss dialogs.
                --}}
                <x-ui.button size="sm"
                             :variant="$typed ? 'danger' : 'primary'"
                             wire:click="approveToolRun({{ $id }})">
                    {{ __('Approve') }}
                </x-ui.button>
            </div>

            <p class="text-2xs leading-relaxed text-[var(--text-muted)]">
                {{ __('Nothing has changed yet. Rejecting stops the run and leaves everything as it is.') }}
            </p>
        @endif
    </div>
</div>
