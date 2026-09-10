@php
    $options = $this->options;
    $affected = $this->affected;
    $locked = $this->locked;
@endphp

<div>
    <form wire:submit="save" class="space-y-4">

        <x-ui.card :title="__('Require two-factor authentication')"
                   :subtitle="__('Enforced on the next request, not the next sign-in.')">

            <div class="space-y-3">
                @foreach ($options as $option)
                    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 rounded-md
                                border border-[var(--line-subtle)] px-3 py-2.5">
                        <x-ui.checkbox wire:model.live="roles"
                                       value="{{ $option['value'] }}"
                                       :disabled="$option['locked']"
                                       :label="$option['label']"
                                       :description="$option['locked']
                                           ? __('Required by this installation. It cannot be turned off here.')
                                           : null" />

                        <div class="flex shrink-0 items-center gap-2 ps-6 sm:ps-0">
                            @if ($option['total'] === 0)
                                <span class="text-xs text-[var(--text-subtle)]">{{ __('Nobody holds this role') }}</span>
                            @elseif ($option['affected'] === 0)
                                <x-ui.badge color="green" size="sm">
                                    {{ trans_choice(
                                        '{1}1 member, already enrolled|[2,*]:count members, all enrolled',
                                        $option['total'],
                                    ) }}
                                </x-ui.badge>
                            @else
                                <x-ui.badge :color="in_array($option['value'], $roles, true) ? 'amber' : 'gray'" size="sm">
                                    {{ trans_choice(
                                        '{1}1 of :total would have to enrol|[2,*]:count of :total would have to enrol',
                                        $option['affected'],
                                        ['total' => $option['total']],
                                    ) }}
                                </x-ui.badge>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <x-slot:footer>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    {{-- The head-count, before the save rather than after it. --}}
                    <p class="text-xs {{ $affected > 0 ? 'text-[var(--text-DEFAULT)]' : 'text-[var(--text-muted)]' }}">
                        @if ($affected === 0)
                            {{ __('Nobody in this workspace would be locked out of their work by saving this.') }}
                        @else
                            <span class="font-medium">
                                {{ trans_choice(
                                    '{1}1 member would be held at enrolment.|[2,*]:count members would be held at enrolment.',
                                    $affected,
                                ) }}
                            </span>
                            {{ __('They keep the sign-out and enrolment screens and nothing else until they finish.') }}
                        @endif
                    </p>

                    <div class="flex items-center gap-2">
                        <span wire:loading wire:target="save" class="text-xs text-[var(--text-muted)]">
                            {{ __('Saving…') }}
                        </span>
                        <x-ui.button type="submit" variant="primary" wire:target="save">
                            {{ __('Save policy') }}
                        </x-ui.button>
                    </div>
                </div>
            </x-slot:footer>
        </x-ui.card>

        <x-ui.card :title="__('What this does not cover')">
            <ul class="space-y-2 text-sm leading-relaxed text-[var(--text-muted)]">
                <li>
                    {{ __('Platform administrators are not workspace members, so no workspace can require a second factor of them. That is set once for the whole installation with PLANVIO_2FA_REQUIRED_ADMINS.') }}
                </li>
                <li>
                    {{ __('This policy applies to :workspace only. Somebody who is an owner here and a member elsewhere is caught by whichever workspace requires their role.', ['workspace' => $workspace->name]) }}
                </li>
                @if ($locked !== [])
                    <li>
                        {{ __('Roles ticked and greyed out are required by config/planvio.php, which applies to every workspace on this server. A workspace can be stricter than that and never looser.') }}
                    </li>
                @endif
                <li>
                    {{ __('Second factors are TOTP codes plus recovery codes. There is no hardware-key or SMS option.') }}
                </li>
            </ul>
        </x-ui.card>
    </form>
</div>
