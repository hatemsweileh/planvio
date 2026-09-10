<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\ProjectTemplate;
use App\Models\Workspace;

/**
 * A template cannot be materialised into a project.
 *
 * `definition` is free-form JSON that may have been authored long before the code reading it,
 * so a template that no longer describes anything usable fails here instead of leaving a
 * half-built project behind.
 */
final class InvalidProjectTemplate extends DomainException
{
    public static function notAvailableIn(ProjectTemplate $template, Workspace $workspace): self
    {
        return new self(
            __('That template is not available in this workspace.'),
            [
                'template_id' => (int) $template->getKey(),
                'template_workspace_id' => $template->workspace_id === null ? null : (int) $template->workspace_id,
                'workspace_id' => (int) $workspace->getKey(),
            ],
        );
    }

    public static function inactive(ProjectTemplate $template): self
    {
        return new self(
            __('That template has been deactivated and cannot be used.'),
            ['template_id' => (int) $template->getKey()],
        );
    }

    public static function malformed(ProjectTemplate $template, string $reason): self
    {
        return new self(
            __('That template cannot be used because its definition is incomplete.'),
            [
                'template_id' => (int) $template->getKey(),
                'reason' => $reason,
            ],
        );
    }
}
