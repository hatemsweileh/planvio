<?php

declare(strict_types=1);

namespace App\Livewire\App\Teams;

use App\Actions\Members\ChangeMemberRole;
use App\Actions\Members\InviteMember;
use App\Actions\Members\RemoveMember;
use App\Enums\WorkspaceRole;
use App\Exceptions\DomainException;
use App\Models\Invitation;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The people of a workspace: who is in it, who has been asked, and how they are grouped.
 *
 * Three surfaces, one screen, because they are one question. An invitation that has not
 * been accepted is a person who is nearly here; a team is a name for a handful of them.
 * Splitting those across routes would make the common job — "get Sam into the design team"
 * — a tour of the product.
 *
 * Every write goes through an Action in `App\Actions\Members`, and every one of them is
 * authorized here first: Actions validate domain invariants and never permissions
 * (ARCHITECTURE.md §2). The last-owner rule is a domain invariant, so it arrives back as a
 * {@see DomainException} carrying a sentence written for the person who tried.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    use WithPagination;

    public Workspace $workspace;

    #[Url(except: 'people')]
    public string $tab = 'people';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /* ---------------------------------------------------------------- *
     * Invite form
     * ---------------------------------------------------------------- */

    public bool $showInvite = false;

    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    /* ---------------------------------------------------------------- *
     * Team form
     * ---------------------------------------------------------------- */

    public bool $showTeam = false;

    public ?int $editingTeamId = null;

    public string $teamName = '';

    public string $teamDescription = '';

    /** @var array<int, int> */
    public array $teamMemberIds = [];

    /** @var array<int, int> */
    public array $teamLeadIds = [];

    public function mount(Workspace $workspace): void
    {
        $this->authorize('view', $workspace);
        $this->authorize('viewAny', [WorkspaceMember::class, $workspace]);

        $this->workspace = $workspace;
    }

    /* ------------------------------------------------------------------ *
     * Reads
     * ------------------------------------------------------------------ */

    /**
     * @return LengthAwarePaginator<int, WorkspaceMember>
     */
    #[Computed]
    public function members(): LengthAwarePaginator
    {
        return WorkspaceMember::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->when($this->search !== '', function (Builder $query): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';

                $query->whereHas('user', fn (Builder $user): Builder => $user
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->with(['user:id,name,email,avatar_path,job_title,is_active'])
            ->orderByRaw($this->roleOrdering())
            ->orderBy('id')
            ->paginate((int) config('planvio.pagination.list', 50), ['*'], 'peoplePage');
    }

    /**
     * Team names per member, for the directory's "Teams" column.
     *
     * Loaded for the visible page only and in one query, rather than as a relation on every
     * row: the directory is a list, not a graph.
     *
     * @return Collection<int, Collection<int, string>>
     */
    #[Computed]
    public function teamsByMember(): Collection
    {
        $userIds = $this->members->getCollection()->pluck('user_id')->all();

        if ($userIds === []) {
            return collect();
        }

        return TeamMember::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('team_id', Team::query()
                ->where('workspace_id', $this->workspace->getKey())
                ->select('id'))
            ->with('team:id,name')
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $rows): Collection => $rows
                ->map(fn (TeamMember $row): string => (string) $row->team?->name)
                ->filter()
                ->values());
    }

    /**
     * @return Collection<int, Invitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        if (! Gate::allows('viewAny', [Invitation::class, $this->workspace])) {
            return collect();
        }

        return Invitation::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->whereNull('accepted_at')
            ->with(['inviter:id,name,avatar_path', 'project:id,name,slug'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /**
     * @return Collection<int, Team>
     */
    #[Computed]
    public function teams(): Collection
    {
        return Team::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->with(['members:id,name,avatar_path'])
            ->withCount('members')
            ->orderBy('name')
            ->get();
    }

    /**
     * Everyone who can be put on a team: the workspace's own members.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function candidates(): Collection
    {
        return User::query()
            ->whereIn('id', WorkspaceMember::query()
                ->where('workspace_id', $this->workspace->getKey())
                ->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'avatar_path']);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return [
            'people' => WorkspaceMember::query()
                ->where('workspace_id', $this->workspace->getKey())
                ->count(),
            'invitations' => $this->invitations->count(),
            'teams' => $this->teams->count(),
        ];
    }

    /**
     * The roles the acting user may hand out.
     *
     * Owner is absent unless the acting user is one: promoting somebody to owner is the
     * owner's own act, and the policy refuses it either way — offering it in the menu would
     * only produce a 403 for anybody else.
     *
     * @return array<string, string>
     */
    public function assignableRoles(): array
    {
        $mine = auth()->user()?->roleIn($this->workspace);

        $roles = [];

        foreach (WorkspaceRole::cases() as $case) {
            if ($case === WorkspaceRole::Owner && $mine !== WorkspaceRole::Owner) {
                continue;
            }

            $roles[$case->value] = $case->label();
        }

        return $roles;
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['people', 'invitations', 'teams'], true) ? $tab : 'people';
    }

    public function updatedSearch(): void
    {
        $this->resetPage('peoplePage');
    }

    /* ------------------------------------------------------------------ *
     * Membership
     * ------------------------------------------------------------------ */

    public function startInvite(): void
    {
        $this->authorize('create', [Invitation::class, $this->workspace]);

        $this->inviteEmail = '';
        $this->inviteRole = WorkspaceRole::Member->value;
        $this->showInvite = true;

        $this->resetValidation();
    }

    public function invite(InviteMember $inviteMember): void
    {
        $this->authorize('create', [Invitation::class, $this->workspace]);

        $data = $this->validate([
            'inviteEmail' => ['required', 'email:rfc', 'max:255'],
            'inviteRole' => ['required', Rule::in(array_keys($this->assignableRoles()))],
        ]);

        try {
            $inviteMember(
                workspace: $this->workspace,
                inviter: $this->actor(),
                email: $data['inviteEmail'],
                role: WorkspaceRole::from($data['inviteRole']),
            );
        } catch (DomainException $failure) {
            $this->addError('inviteEmail', $failure->userMessage());

            return;
        }

        $this->showInvite = false;
        $this->tab = 'invitations';

        unset($this->invitations, $this->counts);

        $this->dispatch('planvio-notify', type: 'success', message: __('Invitation sent to :email.', [
            'email' => $data['inviteEmail'],
        ]));
    }

    public function changeRole(int $userId, string $role, ChangeMemberRole $changeMemberRole): void
    {
        $membership = $this->membership($userId);

        if ($membership === null) {
            return;
        }

        $target = WorkspaceRole::tryFrom($role);

        if ($target === null) {
            return;
        }

        $this->authorize('assignRole', [$membership, $target]);

        try {
            $changeMemberRole(
                workspace: $this->workspace,
                member: $membership->user,
                role: $target,
                actor: $this->actor(),
            );
        } catch (DomainException $failure) {
            $this->dispatch('planvio-notify', type: 'error', message: $failure->userMessage());

            return;
        }

        unset($this->members, $this->counts);

        $this->dispatch('planvio-notify', type: 'success', message: __(':name is now :role.', [
            'name' => $membership->user->name,
            'role' => $target->label(),
        ]));
    }

    public function removeMember(int $userId, RemoveMember $removeMember): void
    {
        $membership = $this->membership($userId);

        if ($membership === null) {
            return;
        }

        $this->authorize('delete', $membership);

        try {
            $removeMember(
                workspace: $this->workspace,
                member: $membership->user,
                actor: $this->actor(),
            );
        } catch (DomainException $failure) {
            $this->dispatch('planvio-notify', type: 'error', message: $failure->userMessage());

            return;
        }

        unset($this->members, $this->teamsByMember, $this->counts, $this->candidates);

        $this->dispatch('planvio-notify', type: 'success', message: __(':name no longer has access.', [
            'name' => $membership->user->name,
        ]));
    }

    /* ------------------------------------------------------------------ *
     * Invitations
     * ------------------------------------------------------------------ */

    public function resendInvitation(int $invitationId, InviteMember $inviteMember): void
    {
        $invitation = $this->invitation($invitationId);

        if ($invitation === null) {
            return;
        }

        $this->authorize('resend', $invitation);

        try {
            // Re-inviting is the resend: the Action revives the same offer, issues a fresh
            // token when the old one has lapsed, and re-sends the mail.
            $inviteMember(
                workspace: $this->workspace,
                inviter: $this->actor(),
                email: (string) $invitation->email,
                role: $invitation->role ?? WorkspaceRole::Member,
                project: $invitation->project,
            );
        } catch (DomainException $failure) {
            $this->dispatch('planvio-notify', type: 'error', message: $failure->userMessage());

            return;
        }

        unset($this->invitations);

        $this->dispatch('planvio-notify', type: 'success', message: __('Invitation re-sent to :email.', [
            'email' => $invitation->email,
        ]));
    }

    public function revokeInvitation(int $invitationId): void
    {
        $invitation = $this->invitation($invitationId);

        if ($invitation === null) {
            return;
        }

        $this->authorize('revoke', $invitation);

        $invitation->delete();

        unset($this->invitations, $this->counts);

        $this->dispatch('planvio-notify', type: 'success', message: __('Invitation for :email revoked.', [
            'email' => $invitation->email,
        ]));
    }

    /* ------------------------------------------------------------------ *
     * Teams
     * ------------------------------------------------------------------ */

    public function startTeam(?int $teamId = null): void
    {
        $team = $teamId === null ? null : $this->team($teamId);

        if ($team === null) {
            $this->authorize('create', [Team::class, $this->workspace]);

            $this->editingTeamId = null;
            $this->teamName = '';
            $this->teamDescription = '';
            $this->teamMemberIds = [];
            $this->teamLeadIds = [];
        } else {
            $this->authorize('update', $team);

            $memberships = TeamMember::query()->forTeam($team)->get();

            $this->editingTeamId = (int) $team->getKey();
            $this->teamName = (string) $team->name;
            $this->teamDescription = (string) $team->description;
            $this->teamMemberIds = $memberships->map(fn (TeamMember $row): int => (int) $row->user_id)->all();
            $this->teamLeadIds = $memberships->where('is_lead', true)
                ->map(fn (TeamMember $row): int => (int) $row->user_id)
                ->values()
                ->all();
        }

        $this->showTeam = true;
        $this->resetValidation();
    }

    public function saveTeam(): void
    {
        $team = $this->editingTeamId === null ? null : $this->team($this->editingTeamId);

        if ($team === null) {
            $this->authorize('create', [Team::class, $this->workspace]);
        } else {
            $this->authorize('update', $team);
        }

        $data = $this->validate([
            'teamName' => ['required', 'string', 'min:2', 'max:80'],
            'teamDescription' => ['nullable', 'string', 'max:500'],
            'teamMemberIds' => ['array'],
            'teamMemberIds.*' => ['integer'],
            'teamLeadIds' => ['array'],
            'teamLeadIds.*' => ['integer'],
        ]);

        // Only people who are actually in this workspace, whatever the form posted: the
        // select is a convenience, not the authority (ARCHITECTURE.md §3).
        $allowed = $this->candidates->pluck('id')->map(intval(...))->all();
        $memberIds = array_values(array_intersect(array_map(intval(...), $data['teamMemberIds']), $allowed));
        $leadIds = array_values(array_intersect(array_map(intval(...), $data['teamLeadIds']), $memberIds));

        $saved = DB::transaction(function () use ($team, $data, $memberIds, $leadIds): Team {
            $team ??= new Team(['workspace_id' => $this->workspace->getKey()]);

            $team->workspace_id = $this->workspace->getKey();
            $team->name = $data['teamName'];
            $team->description = $data['teamDescription'] === '' ? null : $data['teamDescription'];
            $team->slug = $this->teamSlug($data['teamName'], $team->exists ? (int) $team->getKey() : null);
            $team->save();

            $team->members()->sync(
                collect($memberIds)
                    ->mapWithKeys(fn (int $id): array => [$id => ['is_lead' => in_array($id, $leadIds, true)]])
                    ->all(),
            );

            return $team;
        });

        $this->showTeam = false;
        $this->editingTeamId = null;

        unset($this->teams, $this->teamsByMember, $this->counts);

        $this->dispatch('planvio-notify', type: 'success', message: __('“:team” saved.', [
            'team' => $saved->name,
        ]));
    }

    public function deleteTeam(int $teamId): void
    {
        $team = $this->team($teamId);

        if ($team === null) {
            return;
        }

        $this->authorize('delete', $team);

        DB::transaction(function () use ($team): void {
            $team->members()->detach();
            $team->delete();
        });

        unset($this->teams, $this->teamsByMember, $this->counts);

        $this->dispatch('planvio-notify', type: 'success', message: __('“:team” was deleted.', [
            'team' => $team->name,
        ]));
    }

    public function toggleTeamMember(int $userId): void
    {
        $userId = (int) $userId;

        if (in_array($userId, $this->teamMemberIds, true)) {
            $this->teamMemberIds = array_values(array_diff($this->teamMemberIds, [$userId]));
            $this->teamLeadIds = array_values(array_diff($this->teamLeadIds, [$userId]));

            return;
        }

        $this->teamMemberIds[] = $userId;
    }

    public function toggleTeamLead(int $userId): void
    {
        $userId = (int) $userId;

        if (! in_array($userId, $this->teamMemberIds, true)) {
            $this->teamMemberIds[] = $userId;
        }

        $this->teamLeadIds = in_array($userId, $this->teamLeadIds, true)
            ? array_values(array_diff($this->teamLeadIds, [$userId]))
            : [...$this->teamLeadIds, $userId];
    }

    public function render(): View
    {
        return view('livewire.app.teams.index')->title(__('People'));
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    private function membership(int $userId): ?WorkspaceMember
    {
        return WorkspaceMember::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->where('user_id', $userId)
            ->with('user')
            ->first();
    }

    private function invitation(int $invitationId): ?Invitation
    {
        return Invitation::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->whereKey($invitationId)
            ->with('project')
            ->first();
    }

    private function team(int $teamId): ?Team
    {
        return Team::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->whereKey($teamId)
            ->first();
    }

    private function teamSlug(string $name, ?int $ignoreId): string
    {
        $base = Str::slug($name) ?: 'team';
        $slug = $base;
        $suffix = 2;

        while (
            Team::query()
                ->where('workspace_id', $this->workspace->getKey())
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn (Builder $q): Builder => $q->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * Owners first, then admins, and so on down: a directory sorted alphabetically buries
     * the people you are usually looking for.
     *
     * A `CASE` rather than MySQL's `FIELD()`, because the same query runs on SQLite. The
     * values are enum cases from a fixed list, never anything a request supplied.
     */
    private function roleOrdering(): string
    {
        $branches = [];

        foreach (WorkspaceRole::cases() as $position => $case) {
            $branches[] = "when '{$case->value}' then {$position}";
        }

        return 'case role '.implode(' ', $branches).' else 99 end';
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
