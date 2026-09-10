<?php

declare(strict_types=1);

namespace App\Livewire\App\Shared;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SearchResult;
use App\Services\SearchService;
use App\Services\SearchType;
use App\Support\CurrentWorkspace;
use App\Support\RateLimits;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Ctrl/Cmd+K — the fastest way to anywhere in Planvio.
 *
 * Three things make it feel instant rather than merely fast:
 *
 *   1. **It opens with content and no round trip.** The first screen is the projects the
 *      sidebar already knows about — starred and recently opened, handed in by the layout
 *      from the data SetCurrentWorkspace shared. Fetching a "recent items" list on open
 *      would put a request in front of the one interaction that has to be immediate.
 *   2. **It searches on the server, debounced.** {@see SearchService} is workspace-scoped
 *      and permission-filtered, so what comes back is already only what this person may
 *      see — the palette never has to filter results it should not have received.
 *   3. **Every row is a real link or a real button.** The keyboard walks the same elements
 *      the mouse clicks, so Enter, click and a screen reader all do the identical thing.
 *
 * It also lists *actions*, not only records: navigating somewhere is the common case, but
 * "create a project" or "invite someone" are what people actually came to do, and making
 * them hunt for the button is the thing a palette exists to avoid.
 */
final class CommandPalette extends Component
{
    /**
     * Matches {@see SearchService}'s own floor: below two characters a `%x%` scan matches
     * most of the workspace and helps nobody.
     */
    private const MIN_TERM_LENGTH = 2;

    public string $query = '';

    /**
     * Seconds until the search allowance refills, or 0 while there is one.
     *
     * Search is the most expensive read in the product and the palette fires one every
     * quarter of a second somebody is typing, so it charges `security.rate_limits.search`
     * before running — the same ceiling `GET /api/v1/search` is held to. It arrives on
     * Livewire's shared update endpoint, which no route-level limiter can tell apart from
     * a checkbox, so the charge is made here ({@see RateLimits}).
     *
     * A spent allowance stops the query and says so. It never throws: a palette that
     * answers a keystroke with an error dialog is worse than one that pauses.
     */
    public int $searchPausedFor = 0;

    /**
     * Projects the sidebar already resolved, passed in rather than queried again.
     *
     * @var list<array{id: int, name: string, slug: string, color: string|null, starred: bool}>
     */
    public array $recent = [];

    /**
     * @param list<array{id: int, name: string, slug: string, color: string|null, starred: bool}> $recent
     */
    public function mount(array $recent = []): void
    {
        $this->recent = $recent;
    }

    /**
     * Everything the palette is currently offering, as ordered groups of rows.
     *
     * @return list<array{label: string, rows: list<array<string, mixed>>}>
     */
    #[Computed]
    public function groups(): array
    {
        $workspace = app(CurrentWorkspace::class)->get();
        $user = auth()->user();

        if (! $workspace instanceof Workspace || ! $user instanceof User) {
            return [];
        }

        $term = trim($this->query);

        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            $this->searchPausedFor = 0;

            return $this->withoutEmptyGroups([
                ['label' => __('Jump back in'), 'rows' => $this->recentRows($workspace)],
                ['label' => __('Actions'), 'rows' => $this->actionRows($workspace, null)],
            ]);
        }

        $bucket = 'user:'.$user->getKey();

        if (! RateLimits::attempt(RateLimits::SEARCH, $bucket)) {
            $this->searchPausedFor = RateLimits::availableIn(RateLimits::SEARCH, $bucket);

            // The command rows cost nothing to build and are the half of the palette that is
            // not a query, so they stay — unfiltered, exactly as they are before anybody has
            // typed. Narrowing them by a term the search could not run against would leave an
            // empty dialog, which reads as broken rather than as paused.
            return $this->withoutEmptyGroups([
                ['label' => __('Actions'), 'rows' => $this->actionRows($workspace, null)],
            ]);
        }

        $this->searchPausedFor = 0;

        return $this->withoutEmptyGroups(array_merge(
            $this->resultGroups($term, $user, $workspace),
            [
                ['label' => __('Actions'), 'rows' => $this->actionRows($workspace, $term)],
                ['label' => __('Planvio AI'), 'rows' => $this->aiRows($workspace, $term)],
            ],
        ));
    }

    public function render(): View
    {
        return view('livewire.app.shared.command-palette');
    }

    /* ------------------------------------------------------------------ *
     * Rows
     * ------------------------------------------------------------------ */

    /**
     * @return list<array<string, mixed>>
     */
    private function recentRows(Workspace $workspace): array
    {
        $rows = [];

        foreach ($this->recent as $project) {
            $rows[] = [
                'key' => 'recent-project-'.$project['id'],
                'label' => $project['name'],
                'meta' => __('Project'),
                'icon' => ($project['starred'] ?? false) ? 'icon.star' : 'icon.folder',
                'color' => $project['color'] ?? null,
                'href' => route('app.projects.show', [$workspace->slug, $project['slug']]),
            ];
        }

        return $rows;
    }

    /**
     * Search hits, grouped by kind and turned into routable rows.
     *
     * Comments are not searched here: a comment has no page of its own in the route table,
     * and a palette row that cannot be opened is a row that should not be offered.
     *
     * @return list<array{label: string, rows: list<array<string, mixed>>}>
     */
    private function resultGroups(string $term, User $user, Workspace $workspace): array
    {
        $results = app(SearchService::class)->search(
            $term,
            $user,
            $workspace,
            [
                SearchType::Project,
                SearchType::Task,
                SearchType::Milestone,
                SearchType::WikiPage,
                SearchType::User,
                SearchType::Team,
            ],
            $this->perGroup(),
        );

        if ($results->isEmpty()) {
            return [];
        }

        $slugs = $this->projectSlugs($results->all(), $workspace);
        $groups = [];

        foreach ($results->groups() as $type => $hits) {
            $rows = [];

            foreach ($hits as $hit) {
                $row = $this->resultRow($hit, $workspace, $slugs);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            if ($rows !== []) {
                $groups[] = ['label' => SearchType::from($type)->label(), 'rows' => $rows];
            }
        }

        return $groups;
    }

    /**
     * @param array<int, string> $slugs project id => slug
     * @return array<string, mixed>|null null when the hit has no page to open
     */
    private function resultRow(SearchResult $hit, Workspace $workspace, array $slugs): ?array
    {
        $projectSlug = $hit->projectId === null ? null : ($slugs[$hit->projectId] ?? null);

        $href = match ($hit->type) {
            SearchType::Project => $projectSlug === null
                ? null
                : route('app.projects.show', [$workspace->slug, $projectSlug]),

            SearchType::Task => route('app.tasks.show', [$workspace->slug, $hit->id]),

            // Milestones live on the project overview; there is no milestone route.
            SearchType::Milestone => $projectSlug === null
                ? null
                : route('app.projects.show', [$workspace->slug, $projectSlug]),

            SearchType::WikiPage => $projectSlug === null || ! is_string($hit->meta['slug'] ?? null)
                ? null
                : route('app.projects.wiki.show', [$workspace->slug, $projectSlug, $hit->meta['slug']]),

            SearchType::User, SearchType::Team => route('app.teams', $workspace->slug),

            default => null,
        };

        if ($href === null) {
            return null;
        }

        $icon = match ($hit->type) {
            SearchType::Project => 'icon.folder',
            SearchType::Task => 'icon.check-circle',
            SearchType::Milestone => 'icon.flag',
            SearchType::WikiPage => 'icon.document',
            SearchType::User => 'icon.users',
            SearchType::Team => 'icon.users',
            default => 'icon.search',
        };

        return [
            'key' => $hit->type->value.'-'.$hit->id,
            'label' => $hit->title,
            'meta' => $this->resultMeta($hit),
            'icon' => $icon,
            'color' => null,
            'href' => $href,
        ];
    }

    private function resultMeta(SearchResult $hit): ?string
    {
        return match ($hit->type) {
            SearchType::Task, SearchType::Milestone => trim(implode(' · ', array_filter([
                $hit->subtitle,
                $hit->projectName,
            ]))) ?: null,
            default => $hit->subtitle,
        };
    }

    /**
     * One lookup for every project slug the results refer to, rather than one per row.
     *
     * @param list<SearchResult> $results
     * @return array<int, string>
     */
    private function projectSlugs(array $results, Workspace $workspace): array
    {
        $ids = [];

        foreach ($results as $result) {
            if ($result->projectId !== null) {
                $ids[$result->projectId] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> $slugs */
        $slugs = Project::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('id', array_keys($ids))
            ->pluck('slug', 'id')
            ->all();

        return $slugs;
    }

    /**
     * What somebody can *do* from here, filtered by the same capability matrix the menus use.
     *
     * @return list<array<string, mixed>>
     */
    private function actionRows(Workspace $workspace, ?string $term): array
    {
        $rows = [];

        if (Gate::allows('project.create', [Project::class, $workspace])) {
            $rows[] = [
                'key' => 'action-create-project',
                'label' => __('Create project'),
                'meta' => null,
                'icon' => 'icon.plus',
                'color' => null,
                'href' => route('app.projects.create', $workspace->slug),
            ];
        }

        if (Gate::allows('task.create', $workspace)) {
            $rows[] = [
                'key' => 'action-create-task',
                'label' => __('Create task'),
                'meta' => __('C'),
                'icon' => 'icon.check-circle',
                'color' => null,
                'event' => 'open-quick-create',
                'detail' => ['type' => 'task'],
            ];
        }

        $rows[] = $this->navigationRow('my-tasks', __('Go to my tasks'), 'icon.check-circle', route('app.my-tasks', $workspace->slug));
        $rows[] = $this->navigationRow('inbox', __('Go to inbox'), 'icon.inbox', route('app.inbox', $workspace->slug));
        $rows[] = $this->navigationRow('projects', __('Go to projects'), 'icon.folder', route('app.projects.index', $workspace->slug));
        $rows[] = $this->navigationRow('calendar', __('Go to calendar'), 'icon.calendar', route('app.calendar', $workspace->slug));

        if (Gate::allows('reports.view', $workspace)) {
            $rows[] = $this->navigationRow('reports', __('Go to reports'), 'icon.chart', route('app.reports', $workspace->slug));
        }

        if (Gate::allows('users.manage', $workspace)) {
            $rows[] = $this->navigationRow('invite', __('Invite member'), 'icon.users', route('app.teams', $workspace->slug));
        }

        if (Gate::allows('workspace.manage', $workspace)) {
            $rows[] = $this->navigationRow('settings', __('Workspace settings'), 'icon.cog', route('app.settings', $workspace->slug));
        }

        $rows[] = [
            'key' => 'action-shortcuts',
            'label' => __('Keyboard shortcuts'),
            'meta' => '?',
            'icon' => 'icon.monitor',
            'color' => null,
            'event' => 'open-shortcuts-help',
            'detail' => [],
        ];

        if ($term === null) {
            return $rows;
        }

        $needle = mb_strtolower($term);

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => str_contains(mb_strtolower((string) $row['label']), $needle),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function navigationRow(string $key, string $label, string $icon, string $href): array
    {
        return [
            'key' => 'action-'.$key,
            'label' => $label,
            'meta' => null,
            'icon' => $icon,
            'color' => null,
            'href' => $href,
        ];
    }

    /**
     * The escape hatch: whatever was typed, handed straight to the assistant.
     *
     * @return list<array<string, mixed>>
     */
    private function aiRows(Workspace $workspace, string $term): array
    {
        if (! Gate::allows('ai.use', $workspace)) {
            return [];
        }

        return [[
            'key' => 'ai-ask',
            'label' => __('Ask Planvio AI: :query', ['query' => $term]),
            'meta' => null,
            'icon' => 'icon.sparkles',
            'color' => null,
            'event' => 'open-ai-panel',
            'detail' => ['prompt' => $term],
        ]];
    }

    /**
     * @param list<array{label: string, rows: list<array<string, mixed>>}> $groups
     * @return list<array{label: string, rows: list<array<string, mixed>>}>
     */
    private function withoutEmptyGroups(array $groups): array
    {
        return array_values(array_filter($groups, static fn (array $group): bool => $group['rows'] !== []));
    }

    private function perGroup(): int
    {
        $configured = config('planvio.pagination.search', 20);

        // A palette is a shortlist. Twenty rows per group would push the useful ones off
        // the bottom of a list somebody is navigating with arrow keys.
        return max(3, min(6, (int) $configured));
    }
}
