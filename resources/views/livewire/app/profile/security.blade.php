@php
    use App\Support\Bidi;

    $me = $this->user();
    $twoFactorOn = $me->hasTwoFactorEnabled();
@endphp

<div class="page page-prose py-6">

    @include('livewire.app.profile._tabs')

    {{-- ---------------------------------------------------------------- --}}
    {{-- A freshly minted token, shown once                               --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($plainTextToken)
        <div class="mt-5 rounded-lg border border-caution-500/40 bg-caution-50 p-3 dark:bg-caution-950/40"
             role="alert">
            <div class="flex items-start gap-2.5">
                <x-icon.shield class="mt-0.5 size-4 shrink-0 text-caution-600" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-caution-700 dark:text-caution-100">
                        {{ __('Copy your API token now') }}
                    </p>
                    <p class="mt-0.5 text-xs leading-relaxed text-caution-700 dark:text-caution-100">
                        {{ __('Planvio stores only a hash of it. This is the one and only time it is shown — if you lose it, revoke it and make another.') }}
                    </p>

                    <div x-data="copyable(@js($plainTextToken))" class="mt-2 flex flex-wrap items-center gap-2">
                        <code class="min-w-0 flex-1 truncate rounded border border-[var(--line-subtle)]
                                     bg-[var(--surface-panel)] px-2 py-1.5 font-mono text-xs
                                     text-[var(--text-strong)]">{{ $plainTextToken }}</code>
                        <x-ui.button variant="secondary" size="sm" type="button" x-on:click="copy()">
                            <span x-show="!copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                        </x-ui.button>
                    </div>
                </div>

                <x-ui.button variant="ghost" size="sm" icon-only wire:click="dismissToken"
                             :aria-label="__('Dismiss')">
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path d="m5 5 10 10M15 5 5 15" stroke-linecap="round"/>
                    </svg>
                </x-ui.button>
            </div>
        </div>
    @endif

    <div class="mt-5 space-y-4">

        {{-- ------------------------------------------------------------ --}}
        {{-- Two-factor                                                   --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="flex items-center gap-2 text-sm font-semibold text-[var(--text-strong)]">
                        {{ __('Two-factor authentication') }}
                        @if ($twoFactorOn)
                            <x-ui.badge color="green" dot>{{ __('On') }}</x-ui.badge>
                        @elseif ($this->twoFactorRequired())
                            <x-ui.badge color="red" dot>{{ __('Required') }}</x-ui.badge>
                        @else
                            <x-ui.badge color="amber" dot>{{ __('Off') }}</x-ui.badge>
                        @endif
                    </h3>
                    <p class="mt-1 max-w-md text-xs leading-relaxed text-[var(--text-muted)]">
                        {{ __('A six-digit code from your authenticator app, asked for after your password. The QR code is drawn on this server — your shared secret never leaves it.') }}
                    </p>

                    @if ($twoFactorOn)
                        <p class="mt-2 text-xs text-[var(--text-muted)]">
                            {{ trans_choice(
                                '{0} No recovery codes left — generate a new set.|{1} :count recovery code left.|[2,*] :count recovery codes left.',
                                $this->remainingRecoveryCodes(),
                                ['count' => $this->remainingRecoveryCodes()],
                            ) }}
                        </p>
                    @endif
                </div>

                <x-ui.button :href="route('two-factor.setup')" :variant="$twoFactorOn ? 'secondary' : 'primary'"
                             icon="icon.shield">
                    {{ $twoFactorOn ? __('Manage') : __('Turn it on') }}
                </x-ui.button>
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- Password                                                     --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('Password')" :subtitle="$this->passwordPolicy()">
            <form wire:submit="updatePassword" class="space-y-4">
                <x-ui.field :label="__('Current password')" for="pw-current"
                            :error="$errors->first('currentPassword')" required>
                    <x-ui.input id="pw-current" type="password" wire:model="currentPassword"
                                autocomplete="current-password" :invalid="$errors->has('currentPassword')" />
                </x-ui.field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('New password')" for="pw-new" :error="$errors->first('newPassword')" required>
                        <x-ui.input id="pw-new" type="password" wire:model="newPassword"
                                    autocomplete="new-password" :invalid="$errors->has('newPassword')" />
                    </x-ui.field>

                    <x-ui.field :label="__('Repeat it')" for="pw-confirm" required>
                        <x-ui.input id="pw-confirm" type="password" wire:model="newPasswordConfirmation"
                                    autocomplete="new-password" />
                    </x-ui.field>
                </div>

                <div class="flex items-center justify-end gap-2">
                    <span wire:loading wire:target="updatePassword" class="text-xs text-[var(--text-muted)]">
                        {{ __('Changing…') }}
                    </span>
                    <x-ui.button type="submit" variant="primary">{{ __('Change password') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- Sessions                                                     --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card flush>
            <x-slot:header>
                <p class="text-sm font-semibold text-[var(--text-strong)]">{{ __('Where you are signed in') }}</p>
                <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                    {{ __('Every browser holding a live session for this account.') }}
                </p>
            </x-slot:header>

            <x-slot:actions>
                @if ($this->sessionsVisible() && $this->sessions->count() > 1)
                    <x-ui.button variant="secondary" size="sm" wire:click="signOutOthers"
                                 wire:confirm="{{ __('Sign out every other browser? You will stay signed in here.') }}">
                        {{ __('Sign out others') }}
                    </x-ui.button>
                @endif
            </x-slot:actions>

            @if (! $this->sessionsVisible())
                <x-ui.empty-state icon="icon.monitor"
                                  :title="__('Sessions are not stored in the database')"
                                  :description="__('This installation keeps sessions somewhere Planvio cannot list. Changing your password still signs other browsers out.')"
                                  compact />
            @elseif ($this->sessions->isEmpty())
                <x-ui.empty-state icon="icon.monitor"
                                  :title="__('No other sessions')"
                                  :description="__('This is the only browser signed in to your account.')"
                                  compact />
            @else
                <ul class="divide-y divide-[var(--line-subtle)]">
                    @foreach ($this->sessions as $session)
                        <li wire:key="session-{{ $session->id }}"
                            class="flex items-center gap-3 px-3 py-2.5">
                            <span class="grid size-8 shrink-0 place-items-center rounded-md bg-[var(--surface-sunken)]
                                         text-[var(--text-subtle)]">
                                <x-icon.monitor class="size-4" />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-1.5">
                                    <span class="truncate text-sm text-[var(--text-strong)]">
                                        {{ $session->agent ?? __('Unknown browser') }}
                                    </span>
                                    @if ($session->current)
                                        <x-ui.badge color="green" size="sm">{{ __('This browser') }}</x-ui.badge>
                                    @endif
                                </span>
                                <span class="block text-xs text-[var(--text-muted)]">
                                    @if ($session->ip)
                                        <span class="font-mono">{{ $session->ip }}</span>
                                        <span aria-hidden="true">&middot;</span>
                                    @endif
                                    <span x-data="relativeTime('{{ $session->last_active->toIso8601String() }}')"
                                          x-text="label"></span>
                                </span>
                            </span>

                            @unless ($session->current)
                                <x-ui.button variant="ghost" size="sm"
                                             wire:click="revokeSession('{{ $session->id }}')"
                                             :aria-label="__('Sign this browser out')">
                                    {{ __('Sign out') }}
                                </x-ui.button>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- API tokens                                                   --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card flush>
            <x-slot:header>
                <p class="text-sm font-semibold text-[var(--text-strong)]">{{ __('API tokens') }}</p>
                <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                    {{-- The path is isolated: unmarked, the bidi algorithm resolves its leading slash to
                         the paragraph and renders it `api/v1/` inside an Arabic sentence. --}}
                    {{ __('For scripts and integrations calling :path. A token acts as you and can never do more than you can.', [
                        'path' => Bidi::ltr('/api/v1'),
                    ]) }}
                </p>
            </x-slot:header>

            <x-slot:actions>
                <x-ui.button variant="secondary" size="sm" icon="icon.plus" wire:click="startToken">
                    {{ __('New token') }}
                </x-ui.button>
            </x-slot:actions>

            @if ($this->tokens->isEmpty())
                <x-ui.empty-state icon="icon.shield"
                                  :title="__('No tokens yet')"
                                  :description="__('Create one when something other than a browser needs to talk to Planvio — a deployment script, a reporting job, your own tooling.')"
                                  compact>
                    <x-slot:actions>
                        <x-ui.button variant="primary" size="md" icon="icon.plus" wire:click="startToken">
                            {{ __('Create a token') }}
                        </x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            @else
                <ul class="divide-y divide-[var(--line-subtle)]">
                    @foreach ($this->tokens as $token)
                        <li wire:key="token-{{ $token->getKey() }}" class="flex items-center gap-3 px-3 py-2.5">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm text-[var(--text-strong)]">{{ $token->name }}</span>
                                <span class="block text-xs text-[var(--text-muted)]">
                                    {{ __('Created') }}
                                    <span x-data="relativeTime('{{ $token->created_at?->toIso8601String() }}')"
                                          x-text="label"></span>
                                    <span aria-hidden="true">&middot;</span>
                                    @if ($token->last_used_at)
                                        {{ __('last used') }}
                                        <span x-data="relativeTime('{{ $token->last_used_at->toIso8601String() }}')"
                                              x-text="label"></span>
                                    @else
                                        {{ __('never used') }}
                                    @endif
                                </span>
                            </span>

                            <x-ui.button variant="danger-ghost" size="sm"
                                         wire:click="revokeToken({{ $token->getKey() }})"
                                         wire:confirm="{{ __('Revoke “:name”? Anything using it stops working immediately.', ['name' => $token->name]) }}">
                                {{ __('Revoke') }}
                            </x-ui.button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- New token dialog                                                 --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.modal wire:model="showTokenForm" :title="__('New API token')"
                :description="__('Name it after the thing that will use it, so revoking the right one later is obvious.')"
                size="md">
        <x-ui.field :label="__('Name')" for="token-name" :error="$errors->first('tokenName')" required>
            <x-ui.input id="token-name" wire:model="tokenName" maxlength="80"
                        :placeholder="__('Deployment script')" :invalid="$errors->has('tokenName')" autofocus />
        </x-ui.field>

        <x-slot:footer>
            <x-ui.button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="primary" wire:click="createToken" wire:target="createToken">
                {{ __('Create token') }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
