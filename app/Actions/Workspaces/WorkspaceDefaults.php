<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Models\Workspace;
use App\Support\DefaultNames;
use Illuminate\Support\Str;

/**
 * Reads `config('planvio.defaults')` and the per-workspace overrides saved on top of it.
 *
 * A workspace can edit its board template after creation, so the defaults a new project
 * inherits are not the shipped ones but whatever the workspace last saved. Keeping both
 * lookups here means CreateProject never has to know that distinction exists.
 */
final class WorkspaceDefaults
{
    /**
     * Where a workspace's editable board template lives inside `workspaces.settings`.
     */
    public const TASK_STATUS_TEMPLATE_KEY = 'task_status_template';

    /**
     * Column defaults for a brand-new workspace, before the caller's own values.
     *
     * @return array<string, mixed>
     */
    public static function columns(): array
    {
        /** @var array<string, mixed> $configured */
        $configured = (array) config('planvio.defaults.workspace', []);

        return [
            'timezone' => (string) ($configured['timezone'] ?? 'UTC'),
            'locale' => (string) ($configured['locale'] ?? 'en'),
            'currency' => mb_strtoupper(mb_substr((string) ($configured['currency'] ?? 'USD'), 0, 3)),
            'date_format' => (string) ($configured['date_format'] ?? 'Y-m-d'),
            'week_starts_on' => (int) ($configured['week_starts_on'] ?? 1),
        ];
    }

    /**
     * The workspace-level project lifecycle stages shipped with Planvio.
     *
     * @return list<StatusDefinition>
     */
    public static function projectStatuses(): array
    {
        return StatusDefinition::listFrom((array) config('planvio.defaults.project_statuses', []));
    }

    /**
     * The board template a new project starts from: the workspace's own if it saved one,
     * otherwise the shipped default.
     *
     * @return list<StatusDefinition>
     */
    public static function taskStatuses(?Workspace $workspace = null): array
    {
        $settings = $workspace?->settings;
        $saved = is_array($settings) ? ($settings[self::TASK_STATUS_TEMPLATE_KEY] ?? null) : null;

        if (is_array($saved) && $saved !== []) {
            $definitions = StatusDefinition::listFrom($saved);

            if ($definitions !== []) {
                return $definitions;
            }
        }

        return StatusDefinition::listFrom((array) config('planvio.defaults.task_statuses', []));
    }

    /**
     * @return list<array{name: string, slug: string, color: string}>
     */
    public static function tags(): array
    {
        $tags = [];

        foreach ((array) config('planvio.defaults.tags', []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            /*
             * The slug stays derived from the ENGLISH name, deliberately. It is the stable
             * identity a project template refers to when it says a task carries the
             * "urgent" tag, and Str::slug() strips Arabic to an empty string anyway.
             * Only the display name is translated.
             */
            $slug = Str::slug($name);

            if ($slug === '') {
                continue;
            }

            $tags[] = [
                'name' => mb_substr(DefaultNames::translate($name), 0, 255),
                'slug' => mb_substr($slug, 0, 255),
                'color' => trim((string) ($row['color'] ?? '')) ?: 'gray',
            ];
        }

        return $tags;
    }
}
