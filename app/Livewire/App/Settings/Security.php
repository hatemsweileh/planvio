<?php

declare(strict_types=1);

namespace App\Livewire\App\Settings;

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\TwoFactorRequirement;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Who has to hold a second factor before they can do anything in this workspace.
 *
 * `security.two_factor.required_for_roles` has always worked; until now the only way to set
 * it was to edit `config/planvio.php`, which needs filesystem access the workspace owner may
 * not have and a deploy nobody wants to do for a policy change.
 *
 * ## The count is the point of the screen
 *
 * Ticking a role here holds every member who has it at the enrolment page on their next
 * request — no board, no task, no sign-out-and-back-in escape. Doing that to eleven people
 * on a Monday morning without warning is the failure mode, so the number of members who
 * would be caught is recomputed as the boxes are ticked and shown before the save, not
 * after. It counts people who have *not already enrolled*, because somebody who set up an
 * authenticator months ago is not affected at all and including them would inflate the
 * number into meaninglessness.
 *
 * ## What cannot be turned off here
 *
 * Roles named in `config/planvio.php` apply to every workspace and are shown ticked and
 * disabled. A workspace can be stricter than the installation and never looser: the person
 * who controls the server outranks the person who administers one tenant
 * ({@see TwoFactorRequirement}).
 */
final class Security extends Component
{
    public Workspace $workspace;

    /**
     * Role values currently ticked — the installation's own plus this workspace's.
     *
     * @var list<string>
     */
    public array $roles = [];

    public function mount(Workspace $workspace, TwoFactorRequirement $requirement): void
    {
        $this->authorize('update', $workspace);

        $this->workspace = $workspace;
        $this->roles = $requirement->rolesFor($workspace);
    }

    /**
     * Roles the installation requires everywhere, which this screen may not clear.
     *
     * @return list<string>
     */
    #[Computed]
    public function locked(): array
    {
        return app(TwoFactorRequirement::class)->configuredRoles();
    }

    /**
     * Every role, with the head-count that would be forced to enrol if it were ticked.
     *
     * The count is per role rather than only for the whole selection so the choice can be
     * made one line at a time: "owners" is a decision about two people and "members" is a
     * decision about forty.
     *
     * Two grouped queries rather than one pair per role. The rail re-renders on every tick
     * and ten `count(*)` round trips per click on a shared host is a screen that feels
     * broken.
     *
     * @return list<array{value: string, label: string, locked: bool, affected: int, total: int}>
     */
    #[Computed]
    public function options(): array
    {
        $locked = $this->locked;
        $total = $this->countByRole(false);
        $pending = $this->countByRole(true);
        $rows = [];

        foreach (WorkspaceRole::cases() as $case) {
            $rows[] = [
                'value' => $case->value,
                'label' => $case->label(),
                'locked' => in_array($case->value, $locked, true),
                'affected' => $pending[$case->value] ?? 0,
                'total' => $total[$case->value] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * Members of this workspace by role — all of them, or only those yet to enrol.
     *
     * @return array<string, int>
     */
    private function countByRole(bool $withoutTwoFactor): array
    {
        $query = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->getKey());

        if ($withoutTwoFactor) {
            $query->whereHas('user', static function (Builder $user): void {
                // Nested so the OR cannot escape the relation's own join condition.
                $user->where(static function (Builder $inner): void {
                    $inner->whereNull('two_factor_confirmed_at')->orWhereNull('two_factor_secret');
                });
            });
        }

        $counts = [];

        foreach ($query->toBase()->selectRaw('role, count(*) as aggregate')->groupBy('role')->get() as $row) {
            $counts[(string) $row->role] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * How many people the current selection would hold at enrolment.
     */
    #[Computed]
    public function affected(): int
    {
        return app(TwoFactorRequirement::class)->membersWithoutTwoFactor($this->workspace, $this->selection());
    }

    public function save(TwoFactorRequirement $requirement): void
    {
        $this->authorize('update', $this->workspace);

        $this->validate([
            'roles' => ['array'],
            'roles.*' => ['string', Rule::enum(WorkspaceRole::class)],
        ], attributes: ['roles' => __('roles')]);

        $before = $requirement->rolesFor($this->workspace);

        $requirement->store($this->workspace, $this->selection());

        $after = $requirement->rolesFor($this->workspace);

        $this->roles = $after;

        unset($this->options, $this->affected);

        // A change to who must hold a second factor is a security-configuration event, not
        // a project one: it belongs in audit_logs beside the lockouts and the 2FA resets,
        // where it is kept for two years rather than in the workspace activity feed.
        if ($before !== $after) {
            AuditLog::query()->create([
                'user_id' => $this->actor()->getKey(),
                'workspace_id' => $this->workspace->getKey(),
                'event' => 'two_factor.required_roles_changed',
                'description' => __('Two-factor enrolment requirement changed.'),
                'ip' => request()->ip(),
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
                'properties' => ['from' => $before, 'to' => $after],
            ]);
        }

        $this->dispatch('planvio-notify', type: 'success', message: __('Two-factor policy saved.'));
    }

    public function render(): View
    {
        return view('livewire.app.settings.security');
    }

    /**
     * The ticked roles, with the installation's own put back in.
     *
     * A disabled checkbox posts nothing, so without this a save would read the locked roles
     * as unticked. The union is recomputed on read anyway, but writing the honest set keeps
     * the stored row and the screen saying the same thing.
     *
     * @return list<string>
     */
    private function selection(): array
    {
        $valid = array_values(array_filter(
            $this->roles,
            static fn (mixed $role): bool => is_string($role)
                && WorkspaceRole::tryFrom($role) instanceof WorkspaceRole,
        ));

        return array_values(array_unique(array_merge($valid, $this->locked)));
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
