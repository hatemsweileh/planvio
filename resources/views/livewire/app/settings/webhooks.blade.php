@php
    $canCreate = auth()->user()->can('create', [\App\Models\Webhook::class, $workspace]);
    $headers = config('planvio.webhooks.headers');
@endphp

<div class="space-y-4">

    {{-- ---------------------------------------------------------------- --}}
    {{-- The secret, shown once                                           --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($revealedSecret)
        <div class="rounded-lg border border-caution-500/40 bg-caution-50 p-3 dark:bg-caution-950/40"
             role="alert" wire:key="secret-{{ $revealedFor }}">
            <div class="flex items-start gap-2.5">
                <x-icon.shield class="mt-0.5 size-4 shrink-0 text-caution-600" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-caution-700 dark:text-caution-100">
                        {{ __('Copy the signing secret now') }}
                    </p>
                    <p class="mt-0.5 text-xs leading-relaxed text-caution-700 dark:text-caution-100">
                        {{ __('This is the only time it is shown. Planvio stores it to sign deliveries, and no screen reveals it again — if it is lost, rotate it.') }}
                    </p>

                    <div x-data="copyable(@js($revealedSecret))"
                         class="mt-2 flex flex-wrap items-center gap-2">
                        <code class="min-w-0 flex-1 truncate rounded border border-[var(--line-subtle)]
                                     bg-[var(--surface-panel)] px-2 py-1.5 font-mono text-xs
                                     text-[var(--text-strong)]">{{ $revealedSecret }}</code>
                        <x-ui.button variant="secondary" size="sm" type="button" x-on:click="copy()">
                            <span x-show="!copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                        </x-ui.button>
                    </div>
                </div>

                <x-ui.button variant="ghost" size="sm" icon-only wire:click="dismissSecret"
                             :aria-label="__('Dismiss')">
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path d="m5 5 10 10M15 5 5 15" stroke-linecap="round"/>
                    </svg>
                </x-ui.button>
            </div>
        </div>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Endpoints                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <x-slot:header>
            <p class="text-sm font-semibold text-[var(--text-strong)]">{{ __('Endpoints') }}</p>
            <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                {{ __('Every delivery carries :header with a timestamp and an HMAC over the body, so a captured request cannot be replayed later.', ['header' => $headers['signature']]) }}
            </p>
        </x-slot:header>

        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button variant="secondary" size="sm" icon="icon.plus" wire:click="startCreate">
                    {{ __('Add endpoint') }}
                </x-ui.button>
            @endif
        </x-slot:actions>

        @if ($this->webhooks->isEmpty())
            <x-ui.empty-state icon="icon.monitor"
                              :title="__('No endpoints yet')"
                              :description="__('Push task, project and membership events to your own systems — a build server, a data warehouse, a Slack relay. Deliveries are signed, retried and recorded.')">
                <x-slot:actions>
                    @if ($canCreate)
                        <x-ui.button variant="primary" icon="icon.plus" wire:click="startCreate">
                            {{ __('Add the first endpoint') }}
                        </x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-[var(--line-subtle)]">
                @foreach ($this->webhooks as $webhook)
                    <li wire:key="hook-{{ $webhook->getKey() }}">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-3
                                    {{ $webhook->is_active ? '' : 'opacity-60' }}">

                            <span class="mt-0.5 size-2 shrink-0 rounded-full
                                         {{ $webhook->is_active
                                             ? ($webhook->failure_count > 0 ? 'bg-caution-500' : 'bg-positive-500')
                                             : 'bg-ink-400' }}"
                                  aria-hidden="true"></span>

                            <span class="min-w-0 flex-1">
                                <span dir="auto" class="block truncate text-sm font-medium text-[var(--text-strong)]">
                                    {{ $webhook->name }}
                                </span>
                                <span class="block truncate font-mono text-2xs text-[var(--text-subtle)]">
                                    {{ $webhook->url }}
                                </span>
                                <span class="mt-1 flex flex-wrap items-center gap-1">
                                    @foreach ($webhook->subscribedEvents() as $event)
                                        <x-ui.badge :color="$event === '*' ? 'brand' : 'gray'" size="sm">
                                            {{ $event === '*' ? __('All events') : $event }}
                                        </x-ui.badge>
                                    @endforeach
                                </span>
                            </span>

                            <span class="shrink-0 text-end text-xs text-[var(--text-muted)]">
                                @if ($webhook->last_delivered_at)
                                    <span x-data="relativeTime('{{ $webhook->last_delivered_at->toIso8601String() }}')"
                                          x-text="label"></span>
                                @else
                                    <span class="text-[var(--text-subtle)]">{{ __('Never delivered') }}</span>
                                @endif
                                @if ($webhook->failure_count > 0)
                                    <span class="block text-2xs text-caution-600">
                                        {{ trans_choice('{1} :count failure in a row|[2,*] :count failures in a row', $webhook->failure_count, ['count' => $webhook->failure_count]) }}
                                    </span>
                                @endif
                            </span>

                            @can('update', $webhook)
                                <x-ui.dropdown align="end" width="w-60">
                                    <x-slot:trigger>
                                        <x-ui.button variant="ghost" size="sm" icon-only
                                                     :aria-label="__('Actions for :name', ['name' => $webhook->name])">
                                            <x-icon.dots class="size-4" />
                                        </x-ui.button>
                                    </x-slot:trigger>

                                    <x-ui.dropdown-item icon="icon.cog" wire:click="startEdit({{ $webhook->getKey() }})">
                                        {{ __('Edit endpoint') }}
                                    </x-ui.dropdown-item>

                                    @can('test', $webhook)
                                        {{-- Sent while you watch, not queued: the point of a test is
                                             finding out now. --}}
                                        <x-ui.dropdown-item icon="icon.check-circle"
                                                            wire:click="sendTest({{ $webhook->getKey() }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="sendTest({{ $webhook->getKey() }})">
                                            {{ __('Send test delivery') }}
                                        </x-ui.dropdown-item>
                                    @endcan

                                    @can('viewDeliveries', $webhook)
                                        <x-ui.dropdown-item icon="icon.list"
                                                            wire:click="inspect({{ $webhook->getKey() }})">
                                            {{ $inspectingId === $webhook->getKey()
                                                ? __('Hide recent deliveries')
                                                : __('Recent deliveries') }}
                                        </x-ui.dropdown-item>
                                    @endcan

                                    <x-ui.dropdown-item icon="icon.archive"
                                                        wire:click="toggleActive({{ $webhook->getKey() }})">
                                        {{ $webhook->is_active ? __('Pause deliveries') : __('Resume deliveries') }}
                                    </x-ui.dropdown-item>

                                    @can('rotateSecret', $webhook)
                                        <x-ui.dropdown-item icon="icon.shield"
                                                            wire:click="rotateSecret({{ $webhook->getKey() }})"
                                                            wire:confirm="{{ __('Rotate the signing secret? Deliveries signed with the old one stop verifying immediately.') }}">
                                            {{ __('Rotate signing secret') }}
                                        </x-ui.dropdown-item>
                                    @endcan

                                    @can('delete', $webhook)
                                        <x-ui.dropdown-separator />
                                        <x-ui.dropdown-item icon="icon.trash" danger
                                                            wire:click="deleteWebhook({{ $webhook->getKey() }})"
                                                            wire:confirm="{{ __('Delete “:name”? Its delivery history goes with it.', ['name' => $webhook->name]) }}">
                                            {{ __('Delete endpoint') }}
                                        </x-ui.dropdown-item>
                                    @endcan
                                </x-ui.dropdown>
                            @endcan
                        </div>

                        {{-- Deliveries, inline under the endpoint they belong to. --}}
                        @if ($inspectingId === $webhook->getKey())
                            <div class="border-t border-[var(--line-subtle)] bg-[var(--surface-sunken)] px-3 py-2">
                                @if ($this->deliveries->isEmpty())
                                    <p class="py-3 text-center text-xs text-[var(--text-muted)]">
                                        {{ __('Nothing delivered yet. The first matching event will appear here — or send a test delivery to find out now whether the endpoint answers.') }}
                                    </p>
                                @else
                                    <ul class="divide-y divide-[var(--line-subtle)]">
                                        @foreach ($this->deliveries as $delivery)
                                            @php $open = $expandedDeliveryId === $delivery->getKey(); @endphp
                                            <li wire:key="del-{{ $delivery->getKey() }}">
                                                {{-- The whole row is the disclosure: a response body is
                                                     the only thing anybody opens a delivery log for. --}}
                                                <button type="button"
                                                        wire:click="expandDelivery({{ $delivery->getKey() }})"
                                                        aria-expanded="{{ $open ? 'true' : 'false' }}"
                                                        class="flex w-full items-center gap-3 rounded py-1.5 text-start text-xs
                                                               hover:bg-[var(--surface-hover)]
                                                               focus-visible:outline-2 focus-visible:outline-offset-2
                                                               focus-visible:outline-[var(--accent)]">
                                                    <x-ui.badge size="sm"
                                                                :color="$delivery->wasAccepted() ? 'green' : ($delivery->response_status === null ? 'gray' : 'red')">
                                                        {{ $delivery->response_status ?? __('no answer') }}
                                                    </x-ui.badge>
                                                    <span class="min-w-0 flex-1 truncate font-mono text-[var(--text-muted)]">
                                                        {{ $delivery->event }}
                                                    </span>
                                                    @if ($delivery->attempt > 1)
                                                        <span class="text-[var(--text-subtle)]">
                                                            {{ __('attempt :n', ['n' => $delivery->attempt]) }}
                                                        </span>
                                                    @endif
                                                    <span class="shrink-0 text-[var(--text-subtle)]"
                                                          x-data="relativeTime('{{ ($delivery->delivered_at ?? $delivery->created_at)?->toIso8601String() }}')"
                                                          x-text="label"></span>
                                                    <x-icon.chevron-down class="size-3.5 shrink-0 text-[var(--text-subtle)] transition-transform
                                                                                {{ $open ? 'rotate-180' : '' }}" />
                                                </button>

                                                @if ($open)
                                                    <div class="pb-2 ps-1 pe-1">
                                                        @if (filled($delivery->response_body))
                                                            <pre class="max-h-48 overflow-auto rounded border border-[var(--line-subtle)]
                                                                        bg-[var(--surface-panel)] p-2 font-mono text-2xs leading-relaxed
                                                                        whitespace-pre-wrap break-all text-[var(--text-muted)]"
                                                            >{{ $delivery->response_body }}</pre>
                                                            <p class="mt-1 text-2xs text-[var(--text-subtle)]">
                                                                {{ __('Truncated to :bytes bytes and scrubbed of anything that looked like a credential.', ['bytes' => config('planvio.webhooks.max_response_bytes')]) }}
                                                            </p>
                                                        @else
                                                            <p class="py-1 text-2xs text-[var(--text-subtle)]">
                                                                {{ __('The endpoint returned an empty body.') }}
                                                            </p>
                                                        @endif
                                                    </div>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Add / edit                                                       --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.modal wire:model="showForm" :title="$editingId ? __('Edit endpoint') : __('New endpoint')" size="lg">
        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Name')" for="hook-name" :error="$errors->first('name')" required>
                    <x-ui.input id="hook-name" wire:model="name" maxlength="80"
                                :placeholder="__('Build server')" :invalid="$errors->has('name')" />
                </x-ui.field>

                <x-ui.field :label="__('Endpoint URL')" for="hook-url" :error="$errors->first('url')" required>
                    <x-ui.input id="hook-url" type="url" wire:model="url" maxlength="2048"
                                class="font-mono" placeholder="https://example.com/hooks/planvio"
                                :invalid="$errors->has('url')" />
                </x-ui.field>
            </div>

            <div>
                <p class="mb-1.5 text-xs font-medium text-[var(--text-DEFAULT)]">{{ __('Events') }}</p>

                <x-ui.checkbox wire:model.live="allEvents"
                               :label="__('Everything Planvio emits')"
                               :description="__('Including events added in future releases.')" />

                @unless ($allEvents)
                    <div class="mt-3 space-y-3 rounded-lg border border-[var(--line-subtle)] p-3">
                        @foreach ($this->eventGroups() as $group => $names)
                            <div>
                                <p class="mb-1 text-2xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">
                                    {{ $group }}
                                </p>
                                <div class="grid gap-1 sm:grid-cols-2">
                                    @foreach ($names as $event)
                                        <x-ui.checkbox wire:model="events" value="{{ $event }}"
                                                       :label="$event" />
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endunless

                @error('events')
                    <p class="mt-1 text-xs text-critical-600" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <x-ui.checkbox wire:model="isActive"
                           :label="__('Active')"
                           :description="__('Paused endpoints keep their configuration and receive nothing.')" />

            @unless ($editingId)
                <p class="flex items-start gap-1.5 rounded-md bg-[var(--surface-sunken)] p-2.5 text-xs
                          leading-relaxed text-[var(--text-muted)]">
                    <x-icon.shield class="mt-0.5 size-3.5 shrink-0" />
                    {{ __('A signing secret is generated when you save, and shown once on the next screen.') }}
                </p>
            @endunless
        </div>

        <x-slot:footer>
            <x-ui.button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="primary" wire:click="save" wire:target="save">
                {{ $editingId ? __('Save endpoint') : __('Create endpoint') }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
