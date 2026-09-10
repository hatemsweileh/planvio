<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuthorType;
use App\Models\Activity;
use App\Models\AiRun;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use App\Support\Redactor;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;
use UnitEnum;

/**
 * Writes the product-visible change feed (ARCHITECTURE.md §5.4).
 *
 * Actions decide *what* is worth recording; this class owns the shape of the row. It
 * resolves `workspace_id` and `project_id` from the subject so no call site has to
 * remember to, and refuses to write a row it cannot attribute to a tenant — an activity
 * with a null workspace would be visible to everyone or to no one, and both are wrong.
 *
 * Attribution is the other half of the job. `causer_id` is always the human whose authority
 * the change was made under; `causer_type` records whether they typed it themselves or the
 * agent acted for them, and an agent-caused row carries `ai_run_id` so the run stays
 * traceable (§7.1). There is no "system" causer that hides who was responsible.
 *
 * `properties` is rendered back to anyone who may see the subject, so it must never carry
 * secrets. Every path through this class puts it through {@see Redactor}, which strips the
 * keys listed in `config('ai.logging.redact_keys')` — no caller can opt out.
 */
final class ActivityLogger
{
    /**
     * A single property value longer than this is truncated before storage. Activity rows
     * are read thirty at a time; a pasted document in `properties` would make the feed
     * unusable long before it troubled the column.
     */
    private const MAX_VALUE_CHARS = 2000;

    private ?AiRun $aiRun = null;

    private ?User $boundCauser = null;

    private bool $causerBound = false;

    public function __construct(
        private readonly Redactor $redactor,
        private readonly AuthFactory $auth,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    /* ------------------------------------------------------------------ *
     * Attribution
     * ------------------------------------------------------------------ */

    /**
     * Attribute subsequent writes to an agent run.
     *
     * Returns a configured copy. The container holds one logger per request, and a run that
     * re-attributed everything logged after it — including work the user did in the same
     * request — would corrupt the audit trail it exists to protect.
     */
    public function forAi(AiRun $run): self
    {
        $clone = clone $this;
        $clone->aiRun = $run;
        $clone->boundCauser = null;
        $clone->causerBound = false;

        return $clone;
    }

    /**
     * Attribute subsequent writes to an explicit user — for queued jobs and console
     * commands, where there is no authenticated guard to read.
     */
    public function forUser(?User $user): self
    {
        $clone = clone $this;
        $clone->boundCauser = $user;
        $clone->causerBound = true;

        return $clone;
    }

    /* ------------------------------------------------------------------ *
     * Writing
     * ------------------------------------------------------------------ */

    /**
     * Record one change, resolving the causer from the current attribution.
     *
     * The causer is the run's acting user when an AI run is bound, the user given to
     * {@see forUser()} when one is, and otherwise the authenticated user.
     *
     * @param array<string, mixed> $properties
     *
     * @throws RuntimeException when the subject belongs to no resolvable workspace
     */
    public function log(
        Model $subject,
        string $event,
        array $properties = [],
        ?string $description = null,
    ): Activity {
        return $this->record(
            subject: $subject,
            event: $event,
            actor: $this->resolveCauser(),
            properties: $properties,
            description: $description,
            causerType: $this->aiRun instanceof AiRun ? AuthorType::Ai : AuthorType::User,
            aiRunId: $this->aiRun === null ? null : (int) $this->aiRun->getKey(),
        );
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @throws RuntimeException when the subject belongs to no resolvable workspace
     */
    public function record(
        Model $subject,
        string $event,
        ?User $actor = null,
        array $properties = [],
        ?string $description = null,
        AuthorType $causerType = AuthorType::User,
        ?int $aiRunId = null,
    ): Activity {
        return Activity::query()->create([
            'workspace_id' => $this->workspaceIdFor($subject),
            'project_id' => $this->projectIdFor($subject),
            'subject_id' => $subject->getKey(),
            'subject_type' => $subject->getMorphClass(),
            'causer_id' => $actor?->getKey(),
            'causer_type' => $causerType,
            'ai_run_id' => $aiRunId,
            'event' => mb_substr($event, 0, 64),
            'description' => $description,
            'properties' => $this->prepareProperties($properties),
        ]);
    }

    /**
     * One row per subject, written as a single INSERT.
     *
     * Bulk operations still owe the feed one row per record — a single "42 tasks changed"
     * entry cannot be filtered, undone or attributed later. Callers chunk their subjects
     * and call this once per chunk, so neither the row array nor the statement grows with
     * the size of the whole operation.
     *
     * @param iterable<int, Model> $subjects
     * @param (callable(Model): array<string, mixed>)|null $properties
     * @return int the number of rows written
     */
    public function recordEach(
        iterable $subjects,
        string $event,
        ?User $actor = null,
        ?callable $properties = null,
        AuthorType $causerType = AuthorType::User,
        ?int $aiRunId = null,
    ): int {
        $now = Carbon::now();
        $truncatedEvent = mb_substr($event, 0, 64);
        $rows = [];

        foreach ($subjects as $subject) {
            $attributes = $this->prepareProperties($properties === null ? [] : $properties($subject));

            $rows[] = [
                'workspace_id' => $this->workspaceIdFor($subject),
                'project_id' => $this->projectIdFor($subject),
                'subject_id' => $subject->getKey(),
                'subject_type' => $subject->getMorphClass(),
                'causer_id' => $actor?->getKey(),
                'causer_type' => $causerType->value,
                'ai_run_id' => $aiRunId,
                'event' => $truncatedEvent,
                'description' => null,
                // insert() bypasses the model, so the `array` cast does not run here.
                'properties' => $attributes === null
                    ? null
                    : json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        Activity::query()->insert($rows);

        return count($rows);
    }

    /**
     * The same bulk write, attributed the way {@see log()} attributes a single row.
     *
     * @param iterable<int, Model> $subjects
     * @param (callable(Model): array<string, mixed>)|null $properties
     */
    public function logEach(iterable $subjects, string $event, ?callable $properties = null): int
    {
        return $this->recordEach(
            subjects: $subjects,
            event: $event,
            actor: $this->resolveCauser(),
            properties: $properties,
            causerType: $this->aiRun instanceof AiRun ? AuthorType::Ai : AuthorType::User,
            aiRunId: $this->aiRun === null ? null : (int) $this->aiRun->getKey(),
        );
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * The `{attribute, old, new}` shape §5.4 prescribes, built from a model's dirty state.
     *
     * Call it *before* saving: once the model is persisted `getDirty()` is empty. Values
     * are flattened to storable scalars so an enum or a Carbon instance does not land in
     * the JSON column as an object graph.
     *
     * @param list<string> $only limit to these attributes; empty means every dirty one
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function changes(Model $subject, array $only = []): array
    {
        $changes = [];

        foreach (array_keys($subject->getDirty()) as $attribute) {
            if ($only !== [] && ! in_array($attribute, $only, true)) {
                continue;
            }

            $old = self::scalarise($subject->getOriginal($attribute));
            $new = self::scalarise($subject->getAttribute($attribute));

            if ($old === $new) {
                continue;
            }

            $changes[$attribute] = ['old' => $old, 'new' => $new];
        }

        return $changes;
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * Redact first, then bound the size. Both passes run on every path into the table.
     *
     * @param array<string, mixed> $properties
     * @return array<string, mixed>|null
     */
    private function prepareProperties(array $properties): ?array
    {
        if ($properties === []) {
            return null;
        }

        return self::truncate($this->redactor->redact($properties));
    }

    /**
     * @param array<array-key, mixed> $properties
     * @return array<array-key, mixed>
     */
    private static function truncate(array $properties): array
    {
        foreach ($properties as $key => $value) {
            if (is_array($value)) {
                $properties[$key] = self::truncate($value);

                continue;
            }

            if (is_string($value) && mb_strlen($value) > self::MAX_VALUE_CHARS) {
                $properties[$key] = mb_substr($value, 0, self::MAX_VALUE_CHARS).'…';
            }
        }

        return $properties;
    }

    private function resolveCauser(): ?User
    {
        if ($this->causerBound) {
            return $this->boundCauser;
        }

        // An agent run borrows a named user's authority (§7.3), so that user stays the
        // causer and `causer_type` alone records that the agent acted for them.
        if ($this->aiRun instanceof AiRun) {
            $runUser = $this->aiRun->relationLoaded('user') ? $this->aiRun->getRelation('user') : null;

            if ($runUser instanceof User) {
                return $runUser;
            }

            $userId = $this->aiRun->getAttribute('user_id');

            if ($userId !== null) {
                return User::query()->find($userId);
            }
        }

        $user = $this->auth->guard()->user();

        return $user instanceof User ? $user : null;
    }

    private function workspaceIdFor(Model $subject): int
    {
        if ($subject instanceof Workspace) {
            return (int) $subject->getKey();
        }

        $workspaceId = $subject->getAttribute('workspace_id')
            ?? $this->aiRun?->getAttribute('workspace_id')
            ?? $this->currentWorkspace->id();

        if ($workspaceId === null) {
            throw new RuntimeException(sprintf(
                'Cannot record activity for [%s]: no workspace could be resolved from the subject. '
                .'Record the activity against the tenant-scoped parent instead.',
                $subject::class,
            ));
        }

        return (int) $workspaceId;
    }

    /**
     * Project attribution powers the per-project feed (index(project_id, created_at)).
     *
     * Resolution stays free of queries: the column, then an already-loaded morph parent —
     * a comment or attachment logged during a request has its subject in memory anyway —
     * then the run's project. Logging happens inside write transactions on hot paths, and
     * a lazily loaded relation here would add a query per activity.
     */
    private function projectIdFor(Model $subject): ?int
    {
        if ($subject instanceof Project) {
            return (int) $subject->getKey();
        }

        $projectId = $subject->getAttribute('project_id');

        if ($projectId !== null) {
            return (int) $projectId;
        }

        foreach (['commentable', 'attachable', 'subject', 'task', 'milestone', 'project'] as $relation) {
            if (! $subject->relationLoaded($relation)) {
                continue;
            }

            $parent = $subject->getRelation($relation);

            if ($parent instanceof Project) {
                return (int) $parent->getKey();
            }

            if ($parent instanceof Model) {
                $parentProjectId = $parent->getAttribute('project_id');

                if ($parentProjectId !== null) {
                    return (int) $parentProjectId;
                }
            }
        }

        $runProjectId = $this->aiRun?->getAttribute('project_id');

        return $runProjectId === null ? null : (int) $runProjectId;
    }

    private static function scalarise(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value === null || is_scalar($value) || is_array($value)) {
            return $value;
        }

        return is_object($value) && method_exists($value, '__toString') ? (string) $value : null;
    }
}
