<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

/**
 * Global search across the seven things a workspace is made of.
 *
 * ## Why LIKE
 *
 * Every predicate here is `column LIKE ? ESCAPE '!'` against a `%term%` pattern, and that is
 * a deliberate v1 decision rather than a shortcut:
 *
 *  - Planvio ships to shared hosting with no search server, so Elasticsearch, Meilisearch
 *    and Typesense are all out — §9 forbids the infrastructure they need.
 *  - MySQL 8 and MariaDB 10.11 both have InnoDB FULLTEXT, but SQLite — which the whole test
 *    suite runs on — has no MATCH…AGAINST. A relevance model that only exists on one of the
 *    two engines is a relevance model nobody can test.
 *  - Workspaces on this kind of install are thousands of rows, not millions, and every query
 *    below is narrowed by `workspace_id` before the pattern is applied.
 *
 * When that stops being true, the change is local: add `FULLTEXT KEY (title, description)`
 * to `tasks`, `(name, description)` to `projects` and `(title, content)` to `wiki_pages` in a
 * new migration, then swap {@see like()} for a `MATCH … AGAINST (? IN BOOLEAN MODE)` branch
 * chosen on the connection driver. Nothing outside this class needs to know.
 *
 * The escape character is `!`, not the SQL-standard backslash. MySQL processes backslashes
 * inside string literals and SQLite does not, so `ESCAPE '\\'` means different things on the
 * two engines; `!` means exactly one character on both.
 *
 * ## Why it is safe
 *
 * The workspace is passed in and applied to every query, and the caller's role is resolved
 * once, up front. A guest sees only projects they were explicitly added to, and everything
 * hanging off a project — tasks, milestones, comments — is gated through the same
 * `Project::visibleTo()` subquery, so there is one definition of "may see" rather than seven.
 * Teams are withheld from guests entirely: a guest brought in for one project has no business
 * enumerating the organisation. Policies remain the authority (§3); this is the query-side
 * half of the same rule.
 */
final class SearchService
{
    /**
     * Escapes itself, `%` and `_` in a user's term. See the class note on why not backslash.
     */
    private const LIKE_ESCAPE = '!';

    /**
     * Below this, a `%x%` scan matches most of the workspace and helps nobody.
     */
    private const MIN_TERM_LENGTH = 2;

    private const MAX_TERM_LENGTH = 128;

    private const MAX_LIMIT = 100;

    /**
     * @param iterable<int, SearchType|string>|null $types null searches everything
     */
    public function search(
        string $term,
        User $user,
        Workspace $workspace,
        ?iterable $types = null,
        ?int $limit = null,
        bool $includeArchived = false,
    ): SearchResults {
        $term = $this->normaliseTerm($term);
        $limit = $this->resolveLimit($limit);

        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return new SearchResults($term, $limit);
        }

        // Membership is the outermost gate. Someone who is not in the workspace — a platform
        // admin included, per §4.2 — searches nothing here.
        $role = $user->roleIn($workspace);

        if ($role === null) {
            return new SearchResults($term, $limit);
        }

        $pattern = self::pattern($term);
        $prefix = self::prefixPattern($term);
        $results = [];

        foreach (SearchType::normalise($types) as $type) {
            $group = match ($type) {
                SearchType::Project => $this->projects($term, $pattern, $prefix, $user, $workspace, $limit, $includeArchived),
                SearchType::Task => $this->tasks($term, $pattern, $prefix, $user, $workspace, $limit),
                SearchType::Milestone => $this->milestones($pattern, $prefix, $user, $workspace, $limit),
                SearchType::WikiPage => $this->wikiPages($pattern, $prefix, $user, $workspace, $limit),
                SearchType::Comment => $this->comments($pattern, $user, $workspace, $limit),
                SearchType::User => $this->users($pattern, $prefix, $user, $workspace, $role, $limit),
                SearchType::Team => $this->teams($pattern, $prefix, $workspace, $role, $limit),
            };

            foreach ($group as $result) {
                $results[] = $result;
            }
        }

        return SearchResults::fromList($term, $limit, $results);
    }

    /* ------------------------------------------------------------------ *
     * Groups
     * ------------------------------------------------------------------ */

    /**
     * @return list<SearchResult>
     */
    private function projects(
        string $term,
        string $pattern,
        string $prefix,
        User $user,
        Workspace $workspace,
        int $limit,
        bool $includeArchived,
    ): array {
        $query = Project::query()
            ->forWorkspace($workspace)
            ->visibleTo($user);

        if (! $includeArchived) {
            $query->where('projects.is_archived', false);
        }

        $query->where(function (Builder $matches) use ($pattern, $term): void {
            $this->like($matches, 'projects.name', $pattern);
            $this->like($matches, 'projects.description', $pattern, 'or');
            // A project key is a short code, not prose: people type it whole, and an exact
            // match ranks above a fuzzy one every time.
            $matches->orWhere('projects.key', mb_strtoupper($term));
        });

        $rows = $query
            ->addSelect([
                'projects.id',
                'projects.name',
                'projects.key',
                'projects.description',
                'projects.is_archived',
            ])
            ->orderByRaw($this->relevanceExpression('projects.name'), [$prefix])
            ->orderByDesc('projects.updated_at')
            ->limit($limit)
            ->toBase()
            ->get();

        return $this->map($rows, static fn (object $row): SearchResult => new SearchResult(
            type: SearchType::Project,
            id: (int) $row->id,
            title: (string) $row->name,
            subtitle: (string) $row->key,
            excerpt: SearchResult::snippet($row->description),
            projectId: (int) $row->id,
            projectName: (string) $row->name,
            meta: ['is_archived' => (bool) $row->is_archived],
        ));
    }

    /**
     * @return list<SearchResult>
     */
    private function tasks(
        string $term,
        string $pattern,
        string $prefix,
        User $user,
        Workspace $workspace,
        int $limit,
    ): array {
        [$taskKey, $taskNumber] = self::parseTaskReference($term);

        $rows = Task::query()
            ->forWorkspace($workspace)
            ->whereIn('tasks.project_id', $this->visibleProjectIds($user, $workspace))
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->where(function (Builder $matches) use ($pattern, $taskKey, $taskNumber): void {
                $this->like($matches, 'tasks.title', $pattern);
                $this->like($matches, 'tasks.description', $pattern, 'or');

                // "WEB-42" and "#42" are how people refer to a task out loud; neither would
                // ever match the title they are trying to reach.
                if ($taskNumber !== null) {
                    $matches->orWhere(function (Builder $reference) use ($taskKey, $taskNumber): void {
                        $reference->where('tasks.number', $taskNumber);

                        if ($taskKey !== null) {
                            $reference->where('projects.key', $taskKey);
                        }
                    });
                }
            })
            ->addSelect([
                'tasks.id',
                'tasks.title',
                'tasks.description',
                'tasks.number',
                'tasks.project_id',
                'tasks.completed_at',
                'projects.name as project_name',
                'projects.key as project_key',
            ])
            ->orderByRaw($this->relevanceExpression('tasks.title'), [$prefix])
            ->orderByDesc('tasks.updated_at')
            ->limit($limit)
            ->toBase()
            ->get();

        return $this->map($rows, static fn (object $row): SearchResult => new SearchResult(
            type: SearchType::Task,
            id: (int) $row->id,
            title: (string) $row->title,
            subtitle: $row->project_key.'-'.$row->number,
            excerpt: SearchResult::snippet($row->description),
            projectId: (int) $row->project_id,
            projectName: (string) $row->project_name,
            meta: [
                'number' => (int) $row->number,
                'is_completed' => $row->completed_at !== null,
            ],
        ));
    }

    /**
     * @return list<SearchResult>
     */
    private function milestones(
        string $pattern,
        string $prefix,
        User $user,
        Workspace $workspace,
        int $limit,
    ): array {
        $rows = Milestone::query()
            ->forWorkspace($workspace)
            ->whereIn('milestones.project_id', $this->visibleProjectIds($user, $workspace))
            ->join('projects', 'projects.id', '=', 'milestones.project_id')
            ->where(function (Builder $matches) use ($pattern): void {
                $this->like($matches, 'milestones.name', $pattern);
                $this->like($matches, 'milestones.description', $pattern, 'or');
            })
            ->addSelect([
                'milestones.id',
                'milestones.name',
                'milestones.description',
                'milestones.project_id',
                'milestones.status',
                'milestones.due_date',
                'projects.name as project_name',
                'projects.key as project_key',
            ])
            ->orderByRaw($this->relevanceExpression('milestones.name'), [$prefix])
            ->orderByDesc('milestones.updated_at')
            ->limit($limit)
            ->toBase()
            ->get();

        return $this->map($rows, static fn (object $row): SearchResult => new SearchResult(
            type: SearchType::Milestone,
            id: (int) $row->id,
            title: (string) $row->name,
            subtitle: (string) $row->project_key,
            excerpt: SearchResult::snippet($row->description),
            projectId: (int) $row->project_id,
            projectName: (string) $row->project_name,
            meta: [
                'status' => (string) $row->status,
                'due_date' => $row->due_date === null ? null : mb_substr((string) $row->due_date, 0, 10),
            ],
        ));
    }

    /**
     * @return list<SearchResult>
     */
    private function wikiPages(
        string $pattern,
        string $prefix,
        User $user,
        Workspace $workspace,
        int $limit,
    ): array {
        // WikiPage::visibleTo already encodes the project-membership and private-page rules,
        // so the project subquery the other groups use would only repeat it.
        $rows = WikiPage::query()
            ->forWorkspace($workspace)
            ->visibleTo($user)
            ->leftJoin('projects', 'projects.id', '=', 'wiki_pages.project_id')
            ->where(function (Builder $matches) use ($pattern): void {
                $this->like($matches, 'wiki_pages.title', $pattern);
                $this->like($matches, 'wiki_pages.excerpt', $pattern, 'or');
                $this->like($matches, 'wiki_pages.content', $pattern, 'or');
            })
            ->addSelect([
                'wiki_pages.id',
                'wiki_pages.title',
                'wiki_pages.excerpt',
                'wiki_pages.content',
                'wiki_pages.slug',
                'wiki_pages.project_id',
                'wiki_pages.visibility',
                'projects.name as project_name',
            ])
            ->orderByRaw($this->relevanceExpression('wiki_pages.title'), [$prefix])
            ->orderByDesc('wiki_pages.updated_at')
            ->limit($limit)
            ->toBase()
            ->get();

        return $this->map($rows, static fn (object $row): SearchResult => new SearchResult(
            type: SearchType::WikiPage,
            id: (int) $row->id,
            title: (string) $row->title,
            subtitle: $row->project_name === null ? null : (string) $row->project_name,
            excerpt: SearchResult::snippet($row->excerpt) ?? SearchResult::snippet($row->content),
            projectId: $row->project_id === null ? null : (int) $row->project_id,
            projectName: $row->project_name === null ? null : (string) $row->project_name,
            meta: [
                'slug' => (string) $row->slug,
                'visibility' => (string) $row->visibility,
            ],
        ));
    }

    /**
     * Comments are reachable only through a subject the caller may already see.
     *
     * Only comments on tasks, projects and milestones are searched. Anything commented on in
     * future — a wiki page, an attachment — is excluded until its visibility rule is written
     * here, because the safe default for a morph column is to deny what has not been thought
     * through.
     *
     * @return list<SearchResult>
     */
    private function comments(string $pattern, User $user, Workspace $workspace, int $limit): array
    {
        $taskMorph = (new Task)->getMorphClass();
        $projectMorph = (new Project)->getMorphClass();
        $milestoneMorph = (new Milestone)->getMorphClass();

        $comments = Comment::query()
            ->forWorkspace($workspace)
            ->where(function (Builder $matches) use ($pattern): void {
                $this->like($matches, 'comments.body', $pattern);
            })
            ->where(function (Builder $reachable) use ($user, $workspace, $taskMorph, $projectMorph, $milestoneMorph): void {
                $reachable
                    ->where(function (Builder $onTask) use ($user, $workspace, $taskMorph): void {
                        $onTask
                            ->where('comments.commentable_type', $taskMorph)
                            ->whereIn('comments.commentable_id', $this->visibleTaskIds($user, $workspace));
                    })
                    ->orWhere(function (Builder $onProject) use ($user, $workspace, $projectMorph): void {
                        $onProject
                            ->where('comments.commentable_type', $projectMorph)
                            ->whereIn('comments.commentable_id', $this->visibleProjectIds($user, $workspace));
                    })
                    ->orWhere(function (Builder $onMilestone) use ($user, $workspace, $milestoneMorph): void {
                        $onMilestone
                            ->where('comments.commentable_type', $milestoneMorph)
                            ->whereIn('comments.commentable_id', $this->visibleMilestoneIds($user, $workspace));
                    });
            })
            // One extra query per morph type present, not one per comment: the subject label
            // is what makes a matched comment findable, and a lazy relation here would be an
            // N+1 on the busiest read in the product.
            ->with(['commentable' => static function (MorphTo $morphTo): void {
                $morphTo->morphWith([
                    Task::class => ['project'],
                    Milestone::class => ['project'],
                ]);
            }])
            ->orderByDesc('comments.created_at')
            ->limit($limit)
            ->get();

        $results = [];

        foreach ($comments as $comment) {
            $subject = $comment->commentable;

            $results[] = new SearchResult(
                type: SearchType::Comment,
                id: (int) $comment->getKey(),
                title: self::subjectTitle($subject),
                subtitle: self::subjectReference($subject),
                excerpt: SearchResult::snippet($comment->body),
                projectId: self::subjectProjectId($subject),
                projectName: self::subjectProjectName($subject),
                meta: [
                    'commentable_type' => (string) $comment->commentable_type,
                    'commentable_id' => (int) $comment->commentable_id,
                    'author_type' => $comment->author_type?->value,
                ],
            );
        }

        return $results;
    }

    /**
     * @return list<SearchResult>
     */
    private function users(
        string $pattern,
        string $prefix,
        User $user,
        Workspace $workspace,
        WorkspaceRole $role,
        int $limit,
    ): array {
        $query = User::query()
            ->join('workspace_members', function (JoinClause $join) use ($workspace): void {
                $join
                    ->on('workspace_members.user_id', '=', 'users.id')
                    ->where('workspace_members.workspace_id', '=', $workspace->getKey());
            })
            ->where(function (Builder $matches) use ($pattern): void {
                $this->like($matches, 'users.name', $pattern);
                $this->like($matches, 'users.email', $pattern, 'or');
                $this->like($matches, 'users.job_title', $pattern, 'or');
            });

        // A guest is in the workspace for one project. Letting them page through the whole
        // member directory would hand them the org chart of a company they do not work for.
        if ($role === WorkspaceRole::Guest) {
            $query->whereExists(function (QueryBuilder $shared) use ($user, $workspace): void {
                $shared
                    ->selectRaw('1')
                    ->from('project_members')
                    ->whereColumn('project_members.user_id', 'users.id')
                    ->whereIn('project_members.project_id', $this->visibleProjectIds($user, $workspace));
            });
        }

        $rows = $query
            ->addSelect([
                'users.id',
                'users.name',
                'users.email',
                'users.job_title',
                'users.is_active',
                'workspace_members.role as workspace_role',
            ])
            ->orderByRaw($this->relevanceExpression('users.name'), [$prefix])
            ->orderBy('users.name')
            ->limit($limit)
            ->toBase()
            ->get();

        return $this->map($rows, static fn (object $row): SearchResult => new SearchResult(
            type: SearchType::User,
            id: (int) $row->id,
            title: (string) $row->name,
            subtitle: (string) $row->email,
            excerpt: $row->job_title === null ? null : (string) $row->job_title,
            meta: [
                'role' => (string) $row->workspace_role,
                'is_active' => (bool) $row->is_active,
            ],
        ));
    }

    /**
     * @return list<SearchResult>
     */
    private function teams(
        string $pattern,
        string $prefix,
        Workspace $workspace,
        WorkspaceRole $role,
        int $limit,
    ): array {
        if ($role === WorkspaceRole::Guest) {
            return [];
        }

        $rows = Team::query()
            ->forWorkspace($workspace)
            ->where(function (Builder $matches) use ($pattern): void {
                $this->like($matches, 'teams.name', $pattern);
                $this->like($matches, 'teams.description', $pattern, 'or');
                $this->like($matches, 'teams.slug', $pattern, 'or');
            })
            ->addSelect(['teams.id', 'teams.name', 'teams.slug', 'teams.description'])
            ->orderByRaw($this->relevanceExpression('teams.name'), [$prefix])
            ->orderBy('teams.name')
            ->limit($limit)
            ->toBase()
            ->get();

        return $this->map($rows, static fn (object $row): SearchResult => new SearchResult(
            type: SearchType::Team,
            id: (int) $row->id,
            title: (string) $row->name,
            subtitle: (string) $row->slug,
            excerpt: SearchResult::snippet($row->description),
        ));
    }

    /* ------------------------------------------------------------------ *
     * Visibility subqueries
     * ------------------------------------------------------------------ */

    /**
     * The one definition of "projects this user may see", reused by every group that hangs
     * off a project. `toBase()` is what bakes the workspace and soft-delete scopes into the
     * subquery — `getQuery()` would silently drop both.
     */
    private function visibleProjectIds(User $user, Workspace $workspace): QueryBuilder
    {
        return Project::query()
            ->forWorkspace($workspace)
            ->visibleTo($user)
            ->select('projects.id')
            ->toBase();
    }

    private function visibleTaskIds(User $user, Workspace $workspace): QueryBuilder
    {
        return Task::query()
            ->forWorkspace($workspace)
            ->whereIn('tasks.project_id', $this->visibleProjectIds($user, $workspace))
            ->select('tasks.id')
            ->toBase();
    }

    private function visibleMilestoneIds(User $user, Workspace $workspace): QueryBuilder
    {
        return Milestone::query()
            ->forWorkspace($workspace)
            ->whereIn('milestones.project_id', $this->visibleProjectIds($user, $workspace))
            ->select('milestones.id')
            ->toBase();
    }

    /* ------------------------------------------------------------------ *
     * Pattern plumbing
     * ------------------------------------------------------------------ */

    /**
     * @param Builder<Model> $query
     * @param 'and'|'or' $boolean
     */
    private function like(Builder $query, string $column, string $pattern, string $boolean = 'and'): void
    {
        // The ESCAPE character is a literal in the SQL because MySQL will not take a bound
        // parameter there. It is a compile-time constant of this class, never user input.
        $sql = $column." like ? escape '".self::LIKE_ESCAPE."'";

        if ($boolean === 'or') {
            $query->orWhereRaw($sql, [$pattern]);

            return;
        }

        $query->whereRaw($sql, [$pattern]);
    }

    /**
     * Rows whose $column starts with the term sort first. Two tiers, not a score: anything
     * finer would be a number invented to justify an ordering.
     */
    private function relevanceExpression(string $column): string
    {
        return 'case when '.$column." like ? escape '".self::LIKE_ESCAPE."' then 0 else 1 end";
    }

    /**
     * Neutralise the wildcards before wrapping the term in our own.
     *
     * The escape character has to be escaped first; the later passes introduce `!` of their
     * own, and re-escaping those would double them.
     */
    private static function pattern(string $term): string
    {
        return '%'.self::escapeWildcards($term).'%';
    }

    private static function prefixPattern(string $term): string
    {
        return self::escapeWildcards($term).'%';
    }

    private static function escapeWildcards(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $term,
        );
    }

    private function normaliseTerm(string $term): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $term);

        return mb_substr(trim((string) $collapsed), 0, self::MAX_TERM_LENGTH);
    }

    private function resolveLimit(?int $limit): int
    {
        $configured = $limit ?? (int) config('planvio.pagination.search', 20);

        return max(1, min(self::MAX_LIMIT, $configured));
    }

    /**
     * "WEB-42" -> ['WEB', 42]; "#42" or "42" -> [null, 42]; anything else -> [null, null].
     *
     * @return array{0: string|null, 1: int|null}
     */
    private static function parseTaskReference(string $term): array
    {
        if (preg_match('/^#?(\d{1,9})$/', $term, $matches) === 1) {
            return [null, (int) $matches[1]];
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9]{0,11})[-\s](\d{1,9})$/', $term, $matches) === 1) {
            return [mb_strtoupper($matches[1]), (int) $matches[2]];
        }

        return [null, null];
    }

    /**
     * @param Collection<int, object> $rows
     * @param callable(object): SearchResult $factory
     * @return list<SearchResult>
     */
    private function map(Collection $rows, callable $factory): array
    {
        $results = [];

        foreach ($rows as $row) {
            $results[] = $factory($row);
        }

        return $results;
    }

    /* ------------------------------------------------------------------ *
     * Comment subject labelling
     * ------------------------------------------------------------------ */

    private static function subjectTitle(mixed $subject): string
    {
        return match (true) {
            $subject instanceof Task => (string) $subject->title,
            $subject instanceof Project => (string) $subject->name,
            $subject instanceof Milestone => (string) $subject->name,
            default => '',
        };
    }

    private static function subjectReference(mixed $subject): ?string
    {
        if ($subject instanceof Task) {
            $project = $subject->relationLoaded('project') ? $subject->getRelation('project') : null;

            return $project instanceof Project
                ? $project->key.'-'.$subject->number
                : '#'.$subject->number;
        }

        if ($subject instanceof Project) {
            return (string) $subject->key;
        }

        if ($subject instanceof Milestone) {
            $project = $subject->relationLoaded('project') ? $subject->getRelation('project') : null;

            return $project instanceof Project ? (string) $project->key : null;
        }

        return null;
    }

    private static function subjectProjectId(mixed $subject): ?int
    {
        if ($subject instanceof Project) {
            return (int) $subject->getKey();
        }

        if ($subject instanceof Task || $subject instanceof Milestone) {
            return $subject->project_id === null ? null : (int) $subject->project_id;
        }

        return null;
    }

    private static function subjectProjectName(mixed $subject): ?string
    {
        if ($subject instanceof Project) {
            return (string) $subject->name;
        }

        if ($subject instanceof Task || $subject instanceof Milestone) {
            $project = $subject->relationLoaded('project') ? $subject->getRelation('project') : null;

            return $project instanceof Project ? (string) $project->name : null;
        }

        return null;
    }
}
