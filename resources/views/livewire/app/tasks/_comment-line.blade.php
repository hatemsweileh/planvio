@php
    use App\Support\Formats;
@endphp

{{--
    One comment, human or agent.

    An AI comment is labelled as one and carries the run it came from, because a reader has
    to be able to tell a colleague's judgement from a model's output at a glance.
--}}
@php
    $author = $comment->user;
    $isAi = $comment->author_type === \App\Enums\AuthorType::Ai;
    $reactions = $comment->reactions->groupBy('emoji');
@endphp

<div class="flex gap-2.5">
    @if ($isAi)
        <span class="grid size-7 shrink-0 place-items-center rounded-full bg-accent-100 text-accent-600
                     dark:bg-accent-500/20 dark:text-accent-100">
            <x-icon.sparkles class="size-3.5" />
        </span>
    @else
        <x-ui.avatar :user="$author" :name="$author?->name ?? __('Removed user')" size="md" />
    @endif

    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
            <span class="text-xs font-semibold text-[var(--text-strong)]">
                {{ $isAi ? __('Planvio AI') : ($author?->name ?? __('Removed user')) }}
            </span>

            @if ($isAi)
                <x-ui.badge color="purple" size="sm">{{ __('AI') }}</x-ui.badge>
            @endif

            <span class="text-2xs text-[var(--text-subtle)]"
                  x-data="relativeTime('{{ $comment->created_at?->toIso8601String() }}')"
                  x-text="label"
                  title="{{ Formats::using($comment->created_at, 'j M Y, H:i', '') }}">
                {{ $comment->created_at?->diffForHumans() }}
            </span>

            @if ($comment->edited_at)
                <span class="text-2xs text-[var(--text-subtle)]">· {{ __('edited') }}</span>
            @endif

            @can('delete', $comment)
                <button type="button"
                        wire:click="deleteCommentById({{ $comment->getKey() }})"
                        wire:confirm="{{ __('Delete this comment?') }}"
                        class="ms-auto rounded px-1 text-2xs text-[var(--text-subtle)] opacity-0 transition
                               hover:text-critical-600 focus-visible:opacity-100 group-hover:opacity-100">
                    {{ __('Delete') }}
                </button>
            @endcan
        </div>

        {{--
            Stored HTML: sanitised by App\Services\HtmlSanitizer when the comment was written.
            `dir="auto"` so a comment takes its direction from what was typed rather than from
            the reader's interface language — see the note on the task description.
        --}}
        <div dir="auto" class="prose-planvio mt-0.5 text-sm">{!! $comment->body !!}</div>

        <div class="mt-1 flex flex-wrap items-center gap-1">
            @foreach ($reactions as $emoji => $rows)
                @php $mine = $me !== null && $rows->contains('user_id', $me->getKey()); @endphp
                <button type="button"
                        wire:click="toggleReaction({{ $comment->getKey() }}, '{{ $emoji }}')"
                        class="inline-flex h-5 items-center gap-1 rounded-full border px-1.5 text-2xs tabular-nums
                               transition-colors
                               {{ $mine
                                   ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent-soft-text)]'
                                   : 'border-[var(--line-subtle)] bg-[var(--surface-sunken)] text-[var(--text-muted)] hover:border-[var(--line-DEFAULT)]' }}"
                        title="{{ $rows->map(fn ($row) => $row->user?->name)->filter()->join(', ') }}">
                    <span aria-hidden="true">{{ $emoji }}</span>
                    {{ $rows->count() }}
                </button>
            @endforeach

            @can('react', $comment)
                <x-ui.dropdown width="w-auto">
                    <x-slot:trigger>
                        <button type="button"
                                class="grid size-5 place-items-center rounded-full border border-dashed
                                       border-[var(--line-DEFAULT)] text-2xs text-[var(--text-subtle)]
                                       opacity-0 transition hover:border-[var(--line-strong)]
                                       focus-visible:opacity-100 group-hover:opacity-100"
                                aria-label="{{ __('Add a reaction') }}">+</button>
                    </x-slot:trigger>

                    <div class="flex items-center gap-0.5">
                        @foreach ($this->reactionChoices() as $emoji)
                            <button type="button"
                                    wire:click="toggleReaction({{ $comment->getKey() }}, '{{ $emoji }}')"
                                    x-on:click="open = false"
                                    class="grid size-7 place-items-center rounded text-sm transition-colors
                                           hover:bg-[var(--surface-hover)]"
                                    aria-label="{{ $emoji }}">{{ $emoji }}</button>
                        @endforeach
                    </div>
                </x-ui.dropdown>
            @endcan

            @if (! $reply)
                @can('reply', $comment)
                    <button type="button" wire:click="startReply({{ $comment->getKey() }})"
                            class="rounded px-1 text-2xs text-[var(--text-subtle)] transition-colors
                                   hover:text-[var(--text-DEFAULT)]">
                        {{ __('Reply') }}
                    </button>
                @endcan
            @endif
        </div>
    </div>
</div>
