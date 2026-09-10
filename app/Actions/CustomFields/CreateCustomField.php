<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Enums\CustomFieldType;
use App\Events\CustomFields\CustomFieldCreated;
use App\Exceptions\InvalidCustomField;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Define a new attribute on tasks or projects.
 *
 * A field with no `project_id` is workspace-wide and appears on every project; one with a
 * project is local to it. That choice is fixed at creation, because changing it later would
 * either strand the answers already given in one project or expose them to every other.
 *
 * `select` and `multi_select` fields must arrive with options. A choice field with no
 * choices cannot be answered, cannot be filtered on, and is indistinguishable from a broken
 * one — so it is refused rather than created empty and left for somebody to discover.
 */
final class CreateCustomField
{
    /** @var list<string> */
    private const ENTITIES = [CustomField::ENTITY_TASK, CustomField::ENTITY_PROJECT];

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    /**
     * @param list<string>|null $options
     */
    public function __invoke(
        Workspace $workspace,
        User $actor,
        string $name,
        CustomFieldType $type,
        ?string $key = null,
        ?Project $project = null,
        string $entity = CustomField::ENTITY_TASK,
        ?array $options = null,
        bool $isRequired = false,
        ?int $position = null,
    ): CustomField {
        $name = trim($name);

        if ($name === '') {
            throw InvalidCustomField::nameRequired();
        }

        if (! in_array($entity, self::ENTITIES, true)) {
            throw InvalidCustomField::invalidEntity($entity);
        }

        $workspaceId = (int) $workspace->getKey();

        if ($project !== null && (int) $project->workspace_id !== $workspaceId) {
            throw InvalidCustomField::projectInAnotherWorkspace($project, $workspace);
        }

        $projectId = $project === null ? null : (int) $project->getKey();
        $normalisedKey = CustomFieldKey::make($name, $key);

        CustomFieldKey::assertAvailable($normalisedKey, $workspaceId, $projectId, $entity);

        $choices = self::normaliseOptions($type, $options);

        $field = DB::transaction(function () use (
            $workspaceId,
            $projectId,
            $entity,
            $name,
            $normalisedKey,
            $type,
            $choices,
            $isRequired,
            $position,
            $actor,
        ): CustomField {
            $field = CustomField::query()->create([
                'workspace_id' => $workspaceId,
                'project_id' => $projectId,
                'entity' => $entity,
                'name' => mb_substr($name, 0, 255),
                'key' => $normalisedKey,
                'type' => $type,
                'options' => $choices,
                'is_required' => $isRequired,
                'position' => $position ?? self::nextPosition($workspaceId, $projectId, $entity),
                'is_active' => true,
            ]);

            $this->activity->forUser($actor)->log($field, 'created', [
                'name' => $field->name,
                'key' => $normalisedKey,
                'type' => $type->value,
                'entity' => $entity,
                'project_id' => $projectId,
                'is_required' => $isRequired,
            ]);

            return $field;
        });

        $this->events->dispatch(new CustomFieldCreated($field, $actor));

        return $field;
    }

    /**
     * @param list<string>|null $options
     * @return list<string>|null
     */
    public static function normaliseOptions(CustomFieldType $type, ?array $options): ?array
    {
        if (! $type->hasOptions()) {
            // A non-choice field carrying options would show them nowhere and confuse any
            // consumer that reads the column to decide how to render an input.
            return null;
        }

        $choices = [];

        foreach ($options ?? [] as $option) {
            if (! is_scalar($option)) {
                continue;
            }

            $value = trim((string) $option);

            if ($value === '') {
                continue;
            }

            $choices[mb_strtolower($value, 'UTF-8')] = mb_substr($value, 0, 191);
        }

        $choices = array_values($choices);

        if ($choices === []) {
            throw InvalidCustomField::optionsRequired($type);
        }

        return $choices;
    }

    public static function nextPosition(int $workspaceId, ?int $projectId, string $entity): int
    {
        $query = CustomField::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('entity', $entity);

        $query = $projectId === null
            ? $query->whereNull('project_id')
            : $query->where('project_id', $projectId);

        return (int) $query->max('position') + 1;
    }
}
