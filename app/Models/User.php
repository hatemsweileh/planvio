<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Http\Middleware\SetLocale;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable implements FilamentUser, HasLocalePreference
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;
    use SoftDeletes;

    /**
     * `is_admin` is listed deliberately so the admin panel can grant and revoke platform
     * access. Every other privilege-bearing column — remember_token, the two-factor
     * secrets, the login audit columns — is assigned explicitly by the code that owns it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_path',
        'job_title',
        'timezone',
        'locale',
        'is_admin',
        'is_active',
        'theme',
        'notification_preferences',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Resolved workspace roles for this instance, keyed by workspace id.
     *
     * Policies call roleIn() on every authorization check, so a board rendering fifty
     * tasks would otherwise issue fifty identical membership lookups.
     *
     * @var array<int, WorkspaceRole|null>
     */
    private array $workspaceRoleCache = [];

    /**
     * Resolved project roles for this instance, keyed by project id.
     *
     * @var array<int, ProjectRole|null>
     */
    private array $projectRoleCache = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'notification_preferences' => 'array',
        ];
    }

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

    /**
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->withPivot('role', 'title', 'joined_at', 'last_active_at')
            ->withTimestamps();
    }

    /**
     * @return HasMany<WorkspaceMember, $this>
     */
    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /**
     * @return HasMany<Workspace, $this>
     */
    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members')
            ->withPivot('is_lead')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function reportedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'reporter_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * @return HasMany<Favorite, $this>
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /* ------------------------------------------------------------------ *
     * Language
     * ------------------------------------------------------------------ */

    /**
     * The language a notification addressed to this person renders in.
     *
     * Laravel asks this of the notifiable before it builds any channel, and asks nothing
     * when the contract is absent — which is what left every queued notification rendering
     * in whatever locale the worker happened to have, i.e. `config('app.locale')`. An
     * Arabic account received English mail, laid out left to right, on an installation
     * where every screen it links to is Arabic.
     *
     * The `locales` check is the same rule {@see SetLocale} applies:
     * `users.locale` is an ordinary column, and a code left behind by a language that was
     * later switched off is not permission to render in it. Returning null hands the
     * decision back to the application locale, which is where the default lives.
     */
    public function preferredLocale(): ?string
    {
        $code = is_string($this->locale) ? trim($this->locale) : '';

        if ($code === '') {
            return null;
        }

        return Locale::enabledByCode()->has($code) ? $code : null;
    }

    /* ------------------------------------------------------------------ *
     * Panel access
     * ------------------------------------------------------------------ */

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin && $this->is_active;
    }

    /* ------------------------------------------------------------------ *
     * Presentation helpers
     * ------------------------------------------------------------------ */

    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim((string) $this->name), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return mb_strtoupper(mb_substr((string) $this->email, 0, 1));
        }

        $first = mb_substr($words[0], 0, 1);
        $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * An uploaded avatar, or a generated one. Never a third-party URL: a self-hosted
     * install must not leak its users to an external avatar service.
     */
    public function avatarUrl(): string
    {
        $path = $this->avatar_path;

        if (is_string($path) && $path !== '') {
            return Storage::disk('public')->url($path);
        }

        return $this->generatedAvatarUrl();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /* ------------------------------------------------------------------ *
     * Membership resolution
     * ------------------------------------------------------------------ */

    /**
     * The user's role in a workspace, resolved independently of the bound tenant scope.
     *
     * Authorization must never depend on WorkspaceScope being applied (ARCHITECTURE.md §3),
     * so the membership lookup deliberately escapes it.
     */
    public function roleIn(Workspace $workspace): ?WorkspaceRole
    {
        $workspaceId = (int) $workspace->getKey();

        if (array_key_exists($workspaceId, $this->workspaceRoleCache)) {
            return $this->workspaceRoleCache[$workspaceId];
        }

        // A membership already in memory is authoritative; its absence is not, because the
        // relation may have been loaded while a different workspace was bound.
        if ($this->relationLoaded('workspaceMemberships')) {
            $membership = $this->workspaceMemberships->firstWhere('workspace_id', $workspaceId);

            if ($membership !== null) {
                return $this->workspaceRoleCache[$workspaceId] = $membership->role;
            }
        }

        $membership = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $this->getKey())
            ->first(['role']);

        return $this->workspaceRoleCache[$workspaceId] = $membership?->role;
    }

    public function memberOf(Workspace $workspace): bool
    {
        return $this->roleIn($workspace) !== null;
    }

    public function projectRoleIn(Project $project): ?ProjectRole
    {
        $projectId = (int) $project->getKey();

        if (array_key_exists($projectId, $this->projectRoleCache)) {
            return $this->projectRoleCache[$projectId];
        }

        $membership = ProjectMember::query()
            ->where('project_id', $projectId)
            ->where('user_id', $this->getKey())
            ->first(['role']);

        return $this->projectRoleCache[$projectId] = $membership?->role;
    }

    /**
     * Drop the memoised roles after a membership changes within the same request.
     */
    public function flushRoleCache(): void
    {
        $this->workspaceRoleCache = [];
        $this->projectRoleCache = [];
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    private function generatedAvatarUrl(): string
    {
        $palette = ['#3F66B0', '#2F4E8C', '#5B7FC7', '#4C6EA8', '#6B7A99', '#8A6FB0'];
        $seed = (string) ($this->getKey() ?? $this->email ?? $this->name ?? '');
        $background = $palette[abs(crc32($seed)) % count($palette)];

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">'
            .'<rect width="64" height="64" rx="32" fill="'.$background.'"/>'
            .'<text x="32" y="41" text-anchor="middle" font-family="system-ui,-apple-system,sans-serif" '
            .'font-size="26" font-weight="600" fill="#FFFFFF">'
            .htmlspecialchars($this->initials(), ENT_QUOTES | ENT_XML1, 'UTF-8')
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
