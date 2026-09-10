<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Enums\AiMode;
use App\Enums\WorkspaceRole;
use App\Events\Workspaces\WorkspaceCreated;
use App\Exceptions\DomainException;
use App\Models\AiSetting;
use App\Models\ProjectStatus;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Creates a tenant and everything it needs to be usable immediately.
 *
 * The whole shape is one transaction on purpose. A workspace without an owner membership
 * cannot be entered by anybody, one without project statuses cannot have a project, and one
 * without an `ai_settings` row falls back to the global AI defaults instead of its own — so
 * a partial create is not a workspace in a lesser state, it is a workspace nobody can fix.
 *
 * Every row is written with an explicit `workspace_id` rather than relying on the ambient
 * binding: the caller is usually still inside *another* workspace when they create this one.
 */
final class CreateWorkspace
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly WorkspaceSlugGenerator $slugs,
    ) {}

    public function __invoke(User $owner, WorkspaceAttributes $attributes): Workspace
    {
        $name = trim((string) $attributes->name);

        if ($name === '') {
            throw new DomainException(__('A workspace needs a name.'));
        }

        $workspace = DB::transaction(function () use ($owner, $attributes, $name): Workspace {
            $workspace = new Workspace;
            $workspace->fill(WorkspaceDefaults::columns());
            $workspace->fill($attributes->toColumns());

            $workspace->name = $name;
            $workspace->slug = ($this->slugs)($name, $attributes->slug);
            $workspace->owner_id = $owner->getKey();
            $workspace->settings = $this->initialSettings($attributes);
            $workspace->save();

            $this->seedOwnerMembership($workspace, $owner);
            $this->seedProjectStatuses($workspace);
            $this->seedTags($workspace);
            $this->seedAiSettings($workspace);

            $this->activity->forUser($owner)->log($workspace, 'created', [
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ]);

            return $workspace;
        });

        // The owner's cached role predates the membership row written above.
        $owner->flushRoleCache();

        event(new WorkspaceCreated($workspace, $owner));

        return $workspace;
    }

    /**
     * The board template is copied into the workspace at creation rather than read from
     * config forever, so changing the shipped defaults never reshapes an existing tenant's
     * boards and the workspace can edit its own copy.
     *
     * @return array<string, mixed>
     */
    private function initialSettings(WorkspaceAttributes $attributes): array
    {
        $settings = $attributes->settings ?? [];

        if (! array_key_exists(WorkspaceDefaults::TASK_STATUS_TEMPLATE_KEY, $settings)) {
            $settings[WorkspaceDefaults::TASK_STATUS_TEMPLATE_KEY] = StatusDefinition::toArrayList(
                WorkspaceDefaults::taskStatuses(),
            );
        }

        return $settings;
    }

    private function seedOwnerMembership(Workspace $workspace, User $owner): void
    {
        WorkspaceMember::withoutWorkspaceScope()->firstOrCreate(
            [
                'workspace_id' => $workspace->getKey(),
                'user_id' => $owner->getKey(),
            ],
            [
                'role' => WorkspaceRole::Owner,
                'joined_at' => now(),
            ],
        );
    }

    private function seedProjectStatuses(Workspace $workspace): void
    {
        foreach (WorkspaceDefaults::projectStatuses() as $definition) {
            ProjectStatus::query()->create(
                $definition->toProjectStatusAttributes((int) $workspace->getKey()),
            );
        }
    }

    private function seedTags(Workspace $workspace): void
    {
        foreach (WorkspaceDefaults::tags() as $tag) {
            Tag::withoutWorkspaceScope()->firstOrCreate(
                [
                    'workspace_id' => $workspace->getKey(),
                    'slug' => $tag['slug'],
                ],
                [
                    'name' => $tag['name'],
                    'color' => $tag['color'],
                ],
            );
        }
    }

    /**
     * AI is off until an administrator turns it on. The limits are seeded from the platform
     * ceilings so the workspace form opens on the real numbers rather than on column
     * defaults that may no longer match config.
     */
    private function seedAiSettings(Workspace $workspace): void
    {
        AiSetting::query()->firstOrCreate(
            ['workspace_id' => $workspace->getKey()],
            [
                'is_enabled' => false,
                'default_mode' => AiMode::Assistant,
                'autonomous_enabled' => false,
                'max_tool_calls_per_run' => (int) config('ai.limits.max_tool_calls_per_run', 25),
                'max_run_seconds' => (int) config('ai.limits.max_run_seconds', 180),
                'error_threshold' => (int) config('ai.limits.max_errors_per_run', 3),
                'notify_on_action' => true,
                'kill_switch_engaged' => false,
            ],
        );
    }
}
