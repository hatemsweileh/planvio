{{--
    Polls only while it is on screen, and only this component: the dashboard around it is a
    dozen aggregates that have no business being recomputed to refresh eight lines.
--}}
<div wire:poll.visible.30s>
    <x-ui.card :title="__('Recent activity')" flush>
        <x-slot:actions>
            <span class="flex shrink-0 items-center gap-1.5 text-2xs text-[var(--text-subtle)]">
                <span class="relative flex size-1.5" aria-hidden="true">
                    <span class="absolute inline-flex size-full animate-ping rounded-full bg-positive-500 opacity-60"></span>
                    <span class="relative inline-flex size-1.5 rounded-full bg-positive-500"></span>
                </span>
                {{ __('Live') }}
            </span>
        </x-slot:actions>

        @if ($this->activities->isEmpty())
            <x-ui.empty-state icon="icon.list" compact
                              :title="__('Nothing has happened yet')"
                              :description="__('Every change to a task, milestone or document shows up here — yours and the assistant\'s alike.')" />
        @else
            <ul class="divide-y divide-[var(--line-subtle)]">
                @foreach ($this->activities as $activity)
                    @php
                        $isAi = $activity->isFromAi();
                        $url = $this->urlFor($activity);
                        $actor = $activity->causer;
                    @endphp

                    <li wire:key="activity-{{ $activity->getKey() }}"
                        class="relative flex items-start gap-2.5 px-4 py-2.5 transition-colors
                               hover:bg-[var(--surface-hover)] {{ $isAi ? 'bg-accent-50/50 dark:bg-accent-500/[0.06]' : '' }}">

                        {{--
                            Agent work is marked, never blended. A sparkle on a tinted mark says
                            at a glance that nobody typed this, which is the whole basis for
                            trusting the automation enough to leave it switched on.
                        --}}
                        @if ($isAi)
                            <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-accent-100
                                         text-accent-600 ring-1 ring-accent-500/30 dark:bg-accent-500/20
                                         dark:text-accent-100 dark:ring-accent-500/30">
                                <x-dynamic-component :component="$this->iconFor($activity)" class="size-3.5" />
                            </span>
                        @elseif ($actor)
                            <x-ui.avatar :user="$actor" size="sm" class="mt-0.5" />
                        @else
                            <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full
                                         bg-[var(--surface-sunken)] text-[var(--text-subtle)]">
                                <x-dynamic-component :component="$this->iconFor($activity)" class="size-3.5" />
                            </span>
                        @endif

                        <div class="min-w-0 flex-1">
                            <p class="text-sm leading-snug text-[var(--text-DEFAULT)]">
                                <span class="font-medium text-[var(--text-strong)]">
                                    {{ $isAi
                                        ? __('Planvio AI')
                                        : ($actor?->name ?? __('Someone')) }}
                                </span>
                                @if ($url)
                                    <a href="{{ $url }}" class="hover:text-[var(--accent)] hover:underline">
                                        {{ $this->summarise($activity) }}
                                        <span class="absolute inset-0" aria-hidden="true"></span>
                                    </a>
                                @else
                                    <span>{{ $this->summarise($activity) }}</span>
                                @endif
                            </p>

                            <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-2xs text-[var(--text-subtle)]">
                                @if ($isAi)
                                    <x-ui.badge color="purple" size="sm">{{ __('AI') }}</x-ui.badge>
                                @endif
                                @if ($activity->project)
                                    <span class="inline-flex min-w-0 items-center gap-1">
                                        <span class="size-1.5 shrink-0 rounded-full"
                                              style="background-color: {{ $activity->project->color }}"
                                              aria-hidden="true"></span>
                                        <span dir="auto" class="truncate">{{ $activity->project->name }}</span>
                                    </span>
                                    <span aria-hidden="true">&middot;</span>
                                @endif
                                <span x-data="relativeTime('{{ $activity->created_at?->toIso8601String() }}')"
                                      x-text="label"></span>
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
