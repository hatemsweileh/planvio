{{--
    System Information.

    The cron block comes first on purpose: it is the reason docs/CPANEL.md sends people to this
    page, and burying it under version numbers would defeat that.

    Layout is inline style rather than utility classes. The panel loads Filament's own stylesheet,
    not the product's Tailwind build, so utilities the panel does not already use would not be
    compiled — and a page that silently loses its layout is worse than one that carries a few
    declarations. Colours are stated as translucent neutrals so they hold in both themes.
--}}
@php
    $row = 'display: flex; justify-content: space-between; gap: 1rem; align-items: baseline;';
    $term = 'color: var(--fi-color-gray-500, #6b7280);';
    $value = 'text-align: end; word-break: break-word;';
@endphp

<x-filament-panels::page>
    <x-filament::section
        :heading="__('Cron jobs for this installation')"
        :description="__('Copy these into your host\'s cron panel exactly as they appear. The PHP path below is the interpreter this page is running under, so it is the correct one — the account default is frequently a different version.')"
        icon="heroicon-o-clock"
    >
        <div style="display: grid; gap: 1.5rem;">
            @foreach ($cron as $entry)
                <div>
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; flex-wrap: wrap;">
                        <x-filament::badge color="gray" size="sm">{{ $entry['schedule'] }}</x-filament::badge>
                        <span style="{{ $term }} font-size: 0.875rem;">{{ $entry['description'] }}</span>
                    </div>

                    <div
                        x-data="{
                            copied: false,
                            copy() {
                                const text = this.$refs.command.textContent.trim()

                                if (navigator.clipboard && window.isSecureContext) {
                                    navigator.clipboard.writeText(text).then(() => {
                                        this.copied = true
                                        setTimeout(() => (this.copied = false), 2000)
                                    })

                                    return
                                }

                                // Plain HTTP has no clipboard API. Selecting the text is the
                                // honest fallback: the person can still copy it themselves.
                                const range = document.createRange()
                                range.selectNodeContents(this.$refs.command)
                                const selection = window.getSelection()
                                selection.removeAllRanges()
                                selection.addRange(range)
                            },
                        }"
                        style="display: flex; align-items: flex-start; gap: 0.5rem;"
                    >
                        <code
                            x-ref="command"
                            style="flex: 1 1 auto; overflow-x: auto; white-space: pre; padding: 0.625rem 0.75rem; border-radius: 0.5rem; font-size: 0.75rem; line-height: 1.5; background-color: rgba(127, 127, 127, 0.12);"
                        >{{ $entry['schedule'] }} {{ $entry['command'] }}</code>

                        <x-filament::button size="sm" color="gray" x-on:click="copy()">
                            <span x-show="! copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                        </x-filament::button>
                    </div>
                </div>
            @endforeach
        </div>

        <x-slot name="footer">
            <p style="{{ $term }} font-size: 0.875rem;">
                {{ __('System Health reports when the scheduler last ran, which is how you confirm the first line is working. Give it two minutes after adding the entry.') }}
            </p>
        </x-slot>
    </x-filament::section>

    <div style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr));">
        <x-filament::section :heading="__('Application')" icon="heroicon-o-cube">
            <dl style="display: grid; gap: 0.625rem; font-size: 0.875rem;">
                @foreach ($application as $label => $item)
                    <div style="{{ $row }}">
                        <dt style="{{ $term }}">{{ $label }}</dt>
                        <dd style="{{ $value }}">{{ $item }}</dd>
                    </div>
                @endforeach

                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Schema version') }}</dt>
                    <dd style="{{ $value }}">
                        @if ($schema['matches'])
                            {{ $schema['installed'] ?? $schema['expected'] }}
                        @else
                            <x-filament::badge color="warning" size="sm">
                                {{ __('Database :installed, code :expected', ['installed' => $schema['installed'], 'expected' => $schema['expected']]) }}
                            </x-filament::badge>
                        @endif
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section :heading="__('Database')" icon="heroicon-o-circle-stack">
            <dl style="display: grid; gap: 0.625rem; font-size: 0.875rem;">
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Engine') }}</dt>
                    <dd style="{{ $value }}">{{ $database['driver'] }}</dd>
                </div>
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Server version') }}</dt>
                    <dd style="{{ $value }}">{{ $database['version'] }}</dd>
                </div>
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Database') }}</dt>
                    <dd style="{{ $value }}">{{ $database['database'] }}</dd>
                </div>
                @if ($database['host'])
                    <div style="{{ $row }}">
                        <dt style="{{ $term }}">{{ __('Host') }}</dt>
                        <dd style="{{ $value }}">{{ $database['host'] }}</dd>
                    </div>
                @endif
            </dl>
        </x-filament::section>

        <x-filament::section :heading="__('Server')" icon="heroicon-o-server">
            <dl style="display: grid; gap: 0.625rem; font-size: 0.875rem;">
                @foreach ($server as $label => $item)
                    <div style="{{ $row }}">
                        <dt style="{{ $term }}">{{ $label }}</dt>
                        <dd style="{{ $value }}">{{ $item }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        <x-filament::section
            :heading="__('Uploads')"
            :description="__('The smallest of the three is what can actually be uploaded.')"
            icon="heroicon-o-arrow-up-tray"
        >
            <dl style="display: grid; gap: 0.625rem; font-size: 0.875rem;">
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">upload_max_filesize</dt>
                    <dd style="{{ $value }}">{{ $uploads['upload_max_filesize'] }}</dd>
                </div>
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">post_max_size</dt>
                    <dd style="{{ $value }}">{{ $uploads['post_max_size'] }}</dd>
                </div>
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Planvio limit') }}</dt>
                    <dd style="{{ $value }}">{{ $uploads['planvio_limit'] }}</dd>
                </div>
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Effective limit') }}</dt>
                    <dd style="{{ $value }} font-weight: 600;">{{ $uploads['effective'] }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section :heading="__('Storage')" icon="heroicon-o-folder">
            <dl style="display: grid; gap: 0.625rem; font-size: 0.875rem;">
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Default disk') }}</dt>
                    <dd style="{{ $value }}">{{ $storage['default'] }}</dd>
                </div>
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Attachment disk driver') }}</dt>
                    <dd style="{{ $value }}">{{ $storage['private_driver'] }}</dd>
                </div>
                @if ($storage['private_root'])
                    <div style="{{ $row }}">
                        <dt style="{{ $term }}">{{ __('Attachment path') }}</dt>
                        <dd style="{{ $value }}">{{ $storage['private_root'] }}</dd>
                    </div>
                @endif
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('public/storage link') }}</dt>
                    <dd style="{{ $value }}">
                        <x-filament::badge :color="$storage['public_link'] ? 'success' : 'warning'" size="sm">
                            {{ $storage['public_link'] ? __('Present') : __('Missing — avatars and logos will not load') }}
                        </x-filament::badge>
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section :heading="__('Queue and scheduler')" icon="heroicon-o-queue-list">
            <dl style="display: grid; gap: 0.625rem; font-size: 0.875rem;">
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Queue driver') }}</dt>
                    <dd style="{{ $value }}">{{ $queue['driver'] }} ({{ $queue['connection'] }})</dd>
                </div>
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Jobs waiting') }}</dt>
                    <dd style="{{ $value }}">{{ $queue['pending'] ?? __('Unknown') }}</dd>
                </div>
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Failed jobs') }}</dt>
                    <dd style="{{ $value }}">
                        @if (($queue['failed'] ?? 0) > 0)
                            <x-filament::badge color="danger" size="sm">{{ $queue['failed'] }}</x-filament::badge>
                        @else
                            {{ $queue['failed'] ?? __('Unknown') }}
                        @endif
                    </dd>
                </div>
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Scheduler last ran') }}</dt>
                    <dd style="{{ $value }}">
                        @if ($scheduler)
                            <span title="{{ $scheduler->toDayDateTimeString() }}">{{ $scheduler->diffForHumans() }}</span>
                        @else
                            <x-filament::badge color="danger" size="sm">{{ __('Never') }}</x-filament::badge>
                        @endif
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section
            :heading="__('AI')"
            :description="__('Planvio works fully without AI. None of this is required.')"
            icon="heroicon-o-cpu-chip"
        >
            <dl style="display: grid; gap: 0.625rem; font-size: 0.875rem;">
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Status') }}</dt>
                    <dd style="{{ $value }}">
                        @if ($ai['configured'])
                            <x-filament::badge color="success" size="sm">{{ __('Configured') }}</x-filament::badge>
                        @elseif ($ai['enabled'])
                            <x-filament::badge color="warning" size="sm">{{ __('Enabled, no active provider') }}</x-filament::badge>
                        @else
                            <x-filament::badge color="gray" size="sm">{{ __('Switched off') }}</x-filament::badge>
                        @endif
                    </dd>
                </div>
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Provider') }}</dt>
                    <dd style="{{ $value }}">{{ $ai['provider'] ?? __('None') }}</dd>
                </div>
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Driver') }}</dt>
                    <dd style="{{ $value }}">{{ $ai['driver'] ?? '—' }}</dd>
                </div>
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('Model') }}</dt>
                    <dd style="{{ $value }}">{{ $ai['model'] ?? '—' }}</dd>
                </div>
                <div style="{{ $row }}">
                    <dt style="{{ $term }}">{{ __('API key') }}</dt>
                    <dd style="{{ $value }}">{{ $ai['has_key'] ? __('Stored') : __('None stored') }}</dd>
                </div>
            </dl>
        </x-filament::section>
    </div>
</x-filament-panels::page>
