{{--
    The conversation on a task.

    Mentions are written as plain `@name` and resolved on the server against the people who
    are already members of this project — a mention cannot reach somebody who could not read
    the comment in the first place.
--}}
@php
    $me = auth()->user();
    $comments = $this->comments;
@endphp

<div class="space-y-3">

    @if ($canComment)
        <form wire:submit="postComment" class="rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)]">
            <label for="comment-{{ $task->getKey() }}" class="sr-only">{{ __('Write a comment') }}</label>
            {{-- A comment is the person's own words; direction follows what they type. --}}
            <textarea id="comment-{{ $task->getKey() }}"
                      dir="auto"
                      wire:model="commentDraft"
                      rows="2"
                      maxlength="10000"
                      x-data="{ grow() { $el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 240) + 'px' } }"
                      x-init="grow()" x-on:input="grow()"
                      x-on:keydown.meta.enter="$wire.postComment()"
                      x-on:keydown.ctrl.enter="$wire.postComment()"
                      placeholder="{{ __('Write a comment. Use @name to bring somebody in.') }}"
                      class="block w-full resize-none rounded-t-lg border-0 bg-transparent px-3 py-2 text-sm
                             text-[var(--text-strong)] placeholder:text-[var(--text-subtle)] focus:outline-none">{{ $commentDraft }}</textarea>

            <div class="flex items-center gap-2 border-t border-[var(--line-subtle)] px-2 py-1.5">
                <x-ui.dropdown width="w-56">
                    <x-slot:trigger>
                        <x-ui.button size="xs" variant="ghost" :aria-label="__('Mention someone')">@</x-ui.button>
                    </x-slot:trigger>

                    <div class="max-h-64 overflow-y-auto scrollbar-thin">
                        @foreach ($this->taskMembers as $member)
                            {{--
                                The handle is built in PHP and printed with `Js::from`. Written
                                inline as `'@{{ … }}'` it was Blade's own `@{{ }}` escape, so the
                                expression reached the browser as literal text and the handler was
                                a syntax error — the menu item did nothing at all. Quoting is the
                                encoder's job now, so a name with an apostrophe in it is safe too.
                            --}}
                            <x-ui.dropdown-item
                                x-on:click="
                                    $wire.set('commentDraft', ($wire.commentDraft ? $wire.commentDraft.replace(/\s*$/, ' ') : '') + {{ \Illuminate\Support\Js::from('@'.str_replace(' ', '', (string) $member->name).' ') }}, false);
                                    $nextTick(() => document.getElementById('comment-{{ $task->getKey() }}')?.focus());
                                ">
                                <span class="flex items-center gap-2 overflow-hidden">
                                    <x-ui.avatar :user="$member" size="xs" />
                                    <span dir="auto" class="truncate">{{ $member->name }}</span>
                                </span>
                            </x-ui.dropdown-item>
                        @endforeach
                    </div>
                </x-ui.dropdown>

                <p class="text-2xs text-[var(--text-subtle)]">{{ __('Ctrl + Enter to post') }}</p>

                <x-ui.button type="submit" size="sm" variant="primary" class="ms-auto" wire:target="postComment">
                    {{ __('Comment') }}
                </x-ui.button>
            </div>
        </form>
    @endif

    @forelse ($comments as $comment)
        <article wire:key="comment-{{ $comment->getKey() }}" class="group">
            @include('livewire.app.tasks._comment-line', ['comment' => $comment, 'me' => $me, 'reply' => false])

            @if ($comment->replies->isNotEmpty())
                <div class="mt-2 space-y-2 border-s-2 border-[var(--line-subtle)] ps-3">
                    @foreach ($comment->replies as $child)
                        <div wire:key="reply-{{ $child->getKey() }}" class="group">
                            @include('livewire.app.tasks._comment-line', ['comment' => $child, 'me' => $me, 'reply' => true])
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($replyingToId === (int) $comment->getKey())
                <form wire:submit="postReply" class="ms-3 mt-2 border-s-2 border-[var(--accent)] ps-3">
                    <label for="reply-{{ $comment->getKey() }}" class="sr-only">{{ __('Write a reply') }}</label>
                    <textarea id="reply-{{ $comment->getKey() }}"
                              dir="auto"
                              wire:model="replyDraft"
                              rows="2"
                              maxlength="10000"
                              autofocus
                              placeholder="{{ __('Reply…') }}"
                              class="block w-full resize-none rounded-md border border-[var(--line-DEFAULT)]
                                     bg-[var(--surface-panel)] px-2.5 py-1.5 text-sm text-[var(--text-strong)]
                                     placeholder:text-[var(--text-subtle)] focus:border-[var(--accent)]
                                     focus:outline-none focus:ring-2 focus:ring-[var(--accent-ring)]">{{ $replyDraft }}</textarea>

                    <div class="mt-1.5 flex items-center gap-1.5">
                        <x-ui.button type="submit" size="xs" variant="primary" wire:target="postReply">{{ __('Reply') }}</x-ui.button>
                        <x-ui.button size="xs" variant="ghost" wire:click="cancelReply">{{ __('Cancel') }}</x-ui.button>
                    </div>
                </form>
            @endif
        </article>
    @empty
        <x-ui.empty-state compact icon="icon.chat"
                          :title="__('No comments yet')"
                          :description="$canComment
                              ? __('Ask a question, record a decision, or leave the next person a note.')
                              : __('Nobody has said anything on this task.')" />
    @endforelse
</div>
