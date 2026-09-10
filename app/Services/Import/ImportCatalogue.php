<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Tag;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;

/**
 * Everything a row might refer to by name, loaded once.
 *
 * A ten-thousand-row import that looked a status up per row would issue forty thousand
 * queries; a project has at most a handful of columns, milestones and members, so all of
 * them are held in memory for the length of the run and matched with string comparisons.
 *
 * Matching is deliberately forgiving in one direction only. Case and surrounding whitespace
 * are ignored, because "In Progress" and "in progress" are the same column to everyone
 * except a computer. Nothing else is: a status called "Done" does not match "Complete", and
 * a person called "Jon" does not match "John". Guessing at a near miss would attach work to
 * the wrong person, and the warning that says "no match, imported unassigned" is a better
 * outcome than a confident mistake.
 *
 * A name that is genuinely ambiguous — two active members called "Alex Chen" — is treated
 * as no match at all, for the same reason.
 */
final class ImportCatalogue
{
    /** @var array<string, TaskStatus> */
    private array $statuses = [];

    /** @var array<string, User> */
    private array $membersByEmail = [];

    /** @var array<string, User|false> false marks a name held by more than one person */
    private array $membersByName = [];

    /** @var array<string, Tag> */
    private array $tags = [];

    /** @var array<string, Milestone> */
    private array $milestones = [];

    private ?TaskStatus $defaultStatus = null;

    private function __construct() {}

    public static function for(Project $project, Workspace $workspace): self
    {
        $catalogue = new self;

        foreach (TaskStatus::query()->forProject($project)->ordered()->get() as $status) {
            $catalogue->statuses[self::key($status->name)] ??= $status;

            if ($catalogue->defaultStatus === null && $status->is_default) {
                $catalogue->defaultStatus = $status;
            }
        }

        // The board's own fallback, matching CreateTask: the default column, else the first
        // open one. Resolved here so the preview can name it before anything is written.
        $catalogue->defaultStatus ??= TaskStatus::query()
            ->forProject($project)
            ->orderBy('is_completed')
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        foreach ($workspace->members()->where('users.is_active', true)->get(['users.id', 'users.name', 'users.email']) as $member) {
            $catalogue->membersByEmail[self::key((string) $member->email)] = $member;

            $nameKey = self::key((string) $member->name);

            if ($nameKey === '') {
                continue;
            }

            $catalogue->membersByName[$nameKey] = array_key_exists($nameKey, $catalogue->membersByName)
                ? false
                : $member;
        }

        foreach (Tag::query()->forWorkspace($workspace)->ordered()->get(['id', 'workspace_id', 'name', 'slug', 'color']) as $tag) {
            $catalogue->tags[self::key($tag->name)] ??= $tag;
            $catalogue->tags[self::key((string) $tag->slug)] ??= $tag;
        }

        foreach (Milestone::query()->forProject($project)->ordered()->get(['id', 'workspace_id', 'project_id', 'name']) as $milestone) {
            $catalogue->milestones[self::key($milestone->name)] ??= $milestone;
        }

        return $catalogue;
    }

    public function status(string $name): ?TaskStatus
    {
        return $this->statuses[self::key($name)] ?? null;
    }

    public function defaultStatus(): ?TaskStatus
    {
        return $this->defaultStatus;
    }

    /**
     * Email first, then full name. An email is unique by definition; a name is not, so an
     * ambiguous one resolves to nothing.
     */
    public function member(string $reference): ?User
    {
        $key = self::key($reference);

        if ($key === '') {
            return null;
        }

        $byEmail = $this->membersByEmail[$key] ?? null;

        if ($byEmail instanceof User) {
            return $byEmail;
        }

        $byName = $this->membersByName[$key] ?? null;

        return $byName instanceof User ? $byName : null;
    }

    public function isAmbiguousMember(string $reference): bool
    {
        return ($this->membersByName[self::key($reference)] ?? null) === false;
    }

    public function tag(string $name): ?Tag
    {
        return $this->tags[self::key($name)] ?? null;
    }

    /**
     * Remember a tag created mid-import so the next row that names it attaches the same one
     * instead of asking the database again.
     */
    public function rememberTag(Tag $tag): void
    {
        $this->tags[self::key($tag->name)] = $tag;
        $this->tags[self::key((string) $tag->slug)] = $tag;
    }

    public function milestone(string $name): ?Milestone
    {
        return $this->milestones[self::key($name)] ?? null;
    }

    private static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
