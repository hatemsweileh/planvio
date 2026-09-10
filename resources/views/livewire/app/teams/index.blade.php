@php
    $me = auth()->user();
    $canInvite = $me->can('create', [\App\Models\Invitation::class, $workspace]);
    $canManageTeams = $me->can('create', [\App\Models\Team::class, $workspace]);
    $counts = $this->counts;
    $roles = $this->assignableRoles();
@endphp

<div class="page py-6">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Header                                                           --}}
    {{-- ---------------------------------------------------------------- --}}
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-lg font-semibold tracking-tight text-[var(--text-strong)]">{{ __('People') }}</h1>
            <p class="mt-1 max-w-prose text-sm leading-relaxed text-[var(--text-muted)]">
                {{ __('Who can work in :workspace, what they are allowed to do, and how they are grouped.', ['workspace' => $workspace->name]) }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            @if ($canManageTeams)
                <x-ui.button variant="secondary" icon="icon.users" wire:click="startTeam">
                    {{ __('New team') }}
                </x-ui.button>
            @endif
            @if ($canInvite)
                <x-ui.button variant="primary" icon="icon.plus" wire:click="startInvite">
                    {{ __('Invite people') }}
                </x-ui.button>
            @endif
        </div>
    </header>

    <x-ui.tabs class="mt-5">
        <x-ui.tab :active="$tab === 'people'" wire:click="setTab('people')" icon="icon.users"
                  :count="$counts['people']">{{ __('Members') }}</x-ui.tab>
        <x-ui.tab :active="$tab === 'invitations'" wire:click="setTab('invitations')" icon="icon.inbox"
                  :count="$counts['invitations']">{{ __('Invitations') }}</x-ui.tab>
        <x-ui.tab :active="$tab === 'teams'" wire:click="setTab('teams')" icon="icon.folder"
                  :count="$counts['teams']">{{ __('Teams') }}</x-ui.tab>
    </x-ui.tabs>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Members                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($tab === 'people')
        <div class="mt-4">
            <div class="mb-3 w-full sm:max-w-xs">
                <label for="people-search" class="sr-only">{{ __('Search people') }}</label>
                <x-ui.input id="people-search" type="search" icon="icon.search"
                            wire:model.live.debounce.300ms="search" busy-target="search"
                            :placeholder="__('Search by name or email')" />
            </div>

            <x-ui.card flush>
                @if ($this->members->isEmpty())
                    <x-ui.empty-state icon="icon.users"
                                      :title="filled($search) ? __('Nobody matches that') : __('No members yet')"
                                      :description="filled($search)
                                          ? __('Try a different name or email address.')
                                          : __('Invite the people you work with. They get an email with a link that puts them straight into this workspace.')">
                        <x-slot:actions>
                            @if (filled($search))
                                <x-ui.button variant="secondary" wire:click="$set('search', '')">
                                    {{ __('Clear search') }}
                                </x-ui.button>
                            @elseif ($canInvite)
                                <x-ui.button variant="primary" icon="icon.plus" wire:click="startInvite">
                                    {{ __('Invite the first person') }}
                                </x-ui.button>
                            @endif
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <div class="hidden grid-cols-[minmax(0,3fr)_10rem_minmax(0,2fr)_8rem_2.5rem] gap-3 border-b
                                border-[var(--line-subtle)] px-3 py-2 text-2xs font-semibold uppercase
                                tracking-wide text-[var(--text-subtle)] md:grid">
                        <span>{{ __('Person') }}</span>
                        <span>{{ __('Role') }}</span>
                        <span>{{ __('Teams') }}</span>
                        <span>{{ __('Last active') }}</span>
                        <span class="sr-only">{{ __('Actions') }}</span>
                    </div>

                    <ul class="divide-y divide-[var(--line-subtle)]">
                        @foreach ($this->members as $member)
                            @php
                                $canEdit = $me->can('update', $member);
                                $canRemove = $me->can('delete', $member);
                                $memberTeams = $this->teamsByMember->get($member->user_id, collect());
                            @endphp

                            <li wire:key="member-{{ $member->getKey() }}"
                                class="grid grid-cols-1 gap-2 px-3 py-3 transition-colors hover:bg-[var(--surface-hover)]
                                       md:grid-cols-[minmax(0,3fr)_10rem_minmax(0,2fr)_8rem_2.5rem] md:items-center md:gap-3">

                                <div class="flex min-w-0 items-center gap-2.5">
                                    <x-ui.avatar :user="$member->user" size="lg" />
                                    <div class="min-w-0">
                                        <p class="flex items-center gap-1.5">
                                            <span dir="auto" class="truncate text-sm font-medium text-[var(--text-strong)]">
                                                {{ $member->user?->name }}
                                            </span>
                                            @if ((int) $member->user_id === (int) $me->getKey())
                                                <span class="shrink-0 text-2xs text-[var(--text-subtle)]">{{ __('you') }}</span>
                                            @endif
                                            @if ($member->user && ! $member->user->is_active)
                                                <x-ui.badge color="red" size="sm">{{ __('Deactivated') }}</x-ui.badge>
                                            @endif
                                        </p>
                                        <p dir="auto" class="truncate text-xs text-[var(--text-muted)]">
                                            {{ $member->user?->email }}
                                            @if (filled($member->user?->job_title))
                                                <span aria-hidden="true">&middot;</span> {{ $member->user->job_title }}
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    @if ($canEdit)
                                        <label for="role-{{ $member->getKey() }}" class="sr-only">
                                            {{ __('Role for :name', ['name' => $member->user?->name]) }}
                                        </label>
                                        <x-ui.select id="role-{{ $member->getKey() }}" size="sm"
                                                     x-on:change="$wire.changeRole({{ $member->user_id }}, $event.target.value)">
                                            @foreach ($roles as $value => $label)
                                                <option value="{{ $value }}" @selected($member->role?->value === $value)>
                                                    {{ $label }}
                                                </option>
                                            @endforeach
                                            {{-- A role this actor cannot hand out still has to
                                                 show as the current value, or the select would
                                                 silently offer to demote an owner. --}}
                                            @unless (array_key_exists((string) $member->role?->value, $roles))
                                                <option value="{{ $member->role?->value }}" selected>
                                                    {{ $member->role?->label() }}
                                                </option>
                                            @endunless
                                        </x-ui.select>
                                    @else
                                        <x-ui.badge :color="$member->role?->color() ?? 'gray'" dot>
                                            {{ $member->role?->label() }}
                                        </x-ui.badge>
                                    @endif
                                </div>

                                <div class="min-w-0 text-xs text-[var(--text-muted)]">
                                    @if ($memberTeams->isEmpty())
                                        <span class="text-[var(--text-subtle)]">{{ __('—') }}</span>
                                    @else
                                        <span dir="auto" class="line-clamp-2">{{ $memberTeams->join(', ') }}</span>
                                    @endif
                                </div>

                                <div class="text-xs text-[var(--text-muted)]">
                                    @if ($member->last_active_at)
                                        <span x-data="relativeTime('{{ $member->last_active_at->toIso8601String() }}')"
                                              x-text="label"></span>
                                    @else
                                        <span class="text-[var(--text-subtle)]">{{ __('Never') }}</span>
                                    @endif
                                </div>

                                <div class="justify-self-start md:justify-self-end">
                                    @if ($canRemove)
                                        <x-ui.button variant="ghost" size="sm" icon-only
                                                     wire:click="removeMember({{ $member->user_id }})"
                                                     wire:confirm="{{ __('Remove :name from :workspace? They lose access immediately.', ['name' => $member->user?->name, 'workspace' => $workspace->name]) }}"
                                                     :aria-label="__('Remove :name', ['name' => $member->user?->name])">
                                            <x-icon.trash class="size-4" />
                                        </x-ui.button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <x-ui.pagination :paginator="$this->members" page-name="peoplePage" />
                @endif
            </x-ui.card>
        </div>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Invitations                                                      --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($tab === 'invitations')
        <div class="mt-4">
            <x-ui.card flush>
                @if ($this->invitations->isEmpty())
                    <x-ui.empty-state icon="icon.inbox"
                                      :title="__('No invitations outstanding')"
                                      :description="__('Everyone you have asked has either joined or been revoked. Invitations expire on their own, so nothing lingers.')">
                        <x-slot:actions>
                            @if ($canInvite)
                                <x-ui.button variant="primary" icon="icon.plus" wire:click="startInvite">
                                    {{ __('Invite someone') }}
                                </x-ui.button>
                            @endif
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <ul class="divide-y divide-[var(--line-subtle)]">
                        @foreach ($this->invitations as $invitation)
                            <li wire:key="invite-{{ $invitation->getKey() }}"
                                class="flex flex-wrap items-center gap-3 px-3 py-3">
                                <span class="grid size-9 shrink-0 place-items-center rounded-full
                                             bg-[var(--surface-sunken)] text-[var(--text-subtle)]">
                                    <x-icon.inbox class="size-4" />
                                </span>

                                <div class="min-w-0 flex-1">
                                    <p dir="auto" class="truncate text-sm font-medium text-[var(--text-strong)]">
                                        {{ $invitation->email }}
                                    </p>
                                    <p class="flex flex-wrap items-center gap-1.5 text-xs text-[var(--text-muted)]">
                                        <span>{{ __('Invited by :name', ['name' => $invitation->inviter?->name ?? __('Planvio')]) }}</span>
                                        @if ($invitation->project)
                                            <span aria-hidden="true">&middot;</span>
                                            <span>{{ __('For :project only', ['project' => $invitation->project->name]) }}</span>
                                        @endif
                                    </p>
                                </div>

                                <x-ui.badge :color="$invitation->role?->color() ?? 'gray'" dot>
                                    {{ $invitation->role?->label() }}
                                </x-ui.badge>

                                @if ($invitation->is_expired)
                                    <x-ui.badge color="red">{{ __('Expired') }}</x-ui.badge>
                                @else
                                    <span class="text-xs text-[var(--text-muted)]">
                                        {{ __('Expires') }}
                                        <span x-data="relativeTime('{{ $invitation->expires_at?->toIso8601String() }}')"
                                              x-text="label"></span>
                                    </span>
                                @endif

                                @can('resend', $invitation)
                                    <div class="flex items-center gap-1">
                                        <x-ui.button variant="secondary" size="sm"
                                                     wire:click="resendInvitation({{ $invitation->getKey() }})">
                                            {{ __('Resend') }}
                                        </x-ui.button>
                                        <x-ui.button variant="danger-ghost" size="sm"
                                                     wire:click="revokeInvitation({{ $invitation->getKey() }})"
                                                     wire:confirm="{{ __('Revoke the invitation for :email? The link stops working.', ['email' => $invitation->email]) }}">
                                            {{ __('Revoke') }}
                                        </x-ui.button>
                                    </div>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Teams                                                            --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($tab === 'teams')
        <div class="mt-4">
            @if ($this->teams->isEmpty())
                <x-ui.card flush>
                    <x-ui.empty-state icon="icon.folder"
                                      :title="__('No teams yet')"
                                      :description="__('A team is a name for a group of people — Design, Field ops, the Northwind account. Teams carry no permissions of their own; they make assignment and reporting readable.')">
                        <x-slot:actions>
                            @if ($canManageTeams)
                                <x-ui.button variant="primary" icon="icon.plus" wire:click="startTeam">
                                    {{ __('Create a team') }}
                                </x-ui.button>
                            @endif
                        </x-slot:actions>
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($this->teams as $team)
                        <x-ui.card wire:key="team-{{ $team->getKey() }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <h3 class="truncate text-sm font-semibold text-[var(--text-strong)]">
                                        {{ $team->name }}
                                    </h3>
                                    <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                                        {{ trans_choice('{0} No members|{1} :count member|[2,*] :count members', $team->members_count, ['count' => $team->members_count]) }}
                                    </p>
                                </div>

                                @can('update', $team)
                                    <x-ui.dropdown align="end" width="w-48">
                                        <x-slot:trigger>
                                            <x-ui.button variant="ghost" size="sm" icon-only
                                                         :aria-label="__('Actions for :team', ['team' => $team->name])">
                                                <x-icon.dots class="size-4" />
                                            </x-ui.button>
                                        </x-slot:trigger>
                                        <x-ui.dropdown-item icon="icon.cog" wire:click="startTeam({{ $team->getKey() }})">
                                            {{ __('Edit team') }}
                                        </x-ui.dropdown-item>
                                        @can('delete', $team)
                                            <x-ui.dropdown-separator />
                                            <x-ui.dropdown-item icon="icon.trash" danger
                                                                wire:click="deleteTeam({{ $team->getKey() }})"
                                                                wire:confirm="{{ __('Delete the team “:team”? Its members keep their workspace access.', ['team' => $team->name]) }}">
                                                {{ __('Delete team') }}
                                            </x-ui.dropdown-item>
                                        @endcan
                                    </x-ui.dropdown>
                                @endcan
                            </div>

                            @if (filled($team->description))
                                <p dir="auto" class="mt-2 line-clamp-2 text-xs leading-relaxed text-[var(--text-muted)]">
                                    {{ $team->description }}
                                </p>
                            @endif

                            @if ($team->members->isNotEmpty())
                                <div class="mt-3 flex items-center justify-between gap-2">
                                    <x-ui.avatar-stack :users="$team->members" :max="6" size="sm" />

                                    @php
                                        $leads = $team->members->filter(fn ($user) => (bool) $user->pivot->is_lead);
                                    @endphp
                                    @if ($leads->isNotEmpty())
                                        <span class="truncate text-2xs text-[var(--text-subtle)]">
                                            {{ __('Lead: :names', ['names' => $leads->pluck('name')->join(', ')]) }}
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </x-ui.card>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Invite dialog                                                    --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.modal wire:model="showInvite" :title="__('Invite to :workspace', ['workspace' => $workspace->name])"
                :description="__('They receive an email with a link that expires.')" size="md">
        <form wire:submit="invite" class="space-y-4">
            <x-ui.field :label="__('Email address')" for="invite-email" :error="$errors->first('inviteEmail')" required>
                <x-ui.input id="invite-email" type="email" size="lg" wire:model="inviteEmail"
                            autocomplete="off" :placeholder="__('name@company.com')"
                            :invalid="$errors->has('inviteEmail')" />
            </x-ui.field>

            <x-ui.field :label="__('Role')" for="invite-role" :error="$errors->first('inviteRole')"
                        :hint="__('You can change this at any time.')">
                <x-ui.select id="invite-role" size="lg" wire:model="inviteRole"
                             :invalid="$errors->has('inviteRole')">
                    @foreach ($roles as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="primary" wire:click="invite" wire:target="invite">
                {{ __('Send invitation') }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Team dialog                                                      --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.modal wire:model="showTeam"
                :title="$editingTeamId ? __('Edit team') : __('New team')" size="lg">
        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Name')" for="team-name" :error="$errors->first('teamName')" required>
                    <x-ui.input id="team-name" wire:model="teamName" maxlength="80"
                                :placeholder="__('Design, Field ops, Northwind')"
                                :invalid="$errors->has('teamName')" />
                </x-ui.field>

                <x-ui.field :label="__('Description')" for="team-description"
                            :error="$errors->first('teamDescription')">
                    <x-ui.input id="team-description" wire:model="teamDescription" maxlength="500"
                                :placeholder="__('What this team looks after')"
                                :invalid="$errors->has('teamDescription')" />
                </x-ui.field>
            </div>

            <div>
                <p class="mb-1.5 text-xs font-medium text-[var(--text-DEFAULT)]">{{ __('Members') }}</p>
                <p class="mb-2 text-xs text-[var(--text-muted)]">
                    {{ __('Tick to add. The star marks a lead — the person to ask about this team’s work.') }}
                </p>

                <div class="scrollbar-thin max-h-72 divide-y divide-[var(--line-subtle)] overflow-y-auto
                            rounded-lg border border-[var(--line-subtle)]">
                    @foreach ($this->candidates as $candidate)
                        @php
                            $inTeam = in_array((int) $candidate->getKey(), $teamMemberIds, true);
                            $isLead = in_array((int) $candidate->getKey(), $teamLeadIds, true);
                        @endphp
                        <div wire:key="cand-{{ $candidate->getKey() }}"
                             class="flex items-center gap-2.5 px-3 py-2">
                            <input type="checkbox" id="cand-{{ $candidate->getKey() }}"
                                   wire:click="toggleTeamMember({{ $candidate->getKey() }})"
                                   @checked($inTeam)
                                   class="size-4 shrink-0 rounded border-[var(--line-strong)]
                                          bg-[var(--surface-panel)] text-[var(--accent)] shadow-xs
                                          checked:border-[var(--accent)] checked:bg-[var(--accent)]
                                          focus:ring-2 focus:ring-[var(--accent-ring)]">

                            <label for="cand-{{ $candidate->getKey() }}"
                                   class="flex min-w-0 flex-1 cursor-pointer items-center gap-2">
                                <x-ui.avatar :user="$candidate" size="sm" />
                                <span class="min-w-0">
                                    <span dir="auto" class="block truncate text-sm text-[var(--text-DEFAULT)]">{{ $candidate->name }}</span>
                                    <span dir="auto" class="block truncate text-2xs text-[var(--text-subtle)]">{{ $candidate->email }}</span>
                                </span>
                            </label>

                            <button type="button" wire:click="toggleTeamLead({{ $candidate->getKey() }})"
                                    class="grid size-7 shrink-0 place-items-center rounded transition-colors
                                           {{ $isLead
                                               ? 'text-caution-500'
                                               : 'text-[var(--text-subtle)] hover:bg-[var(--surface-hover)]' }}"
                                    aria-pressed="{{ $isLead ? 'true' : 'false' }}"
                                    aria-label="{{ __('Make :name a lead', ['name' => $candidate->name]) }}">
                                <x-icon.star class="size-4" />
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <x-slot:footer>
            <x-ui.button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="primary" wire:click="saveTeam" wire:target="saveTeam">
                {{ $editingTeamId ? __('Save team') : __('Create team') }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
