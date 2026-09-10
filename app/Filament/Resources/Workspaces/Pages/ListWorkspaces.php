<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workspaces\Pages;

use App\Filament\Resources\Workspaces\WorkspaceResource;
use Filament\Resources\Pages\ListRecords;

/**
 * There is no "New workspace" button, and that is not an omission.
 *
 * A workspace created here would have no owner sitting in front of it, no seeded statuses
 * chosen for the work it is for, and no membership for the person who asked for it. Creation
 * belongs to `App\Actions\Workspaces\CreateWorkspace`, driven from the product by the person
 * who will own the result.
 */
final class ListWorkspaces extends ListRecords
{
    protected static string $resource = WorkspaceResource::class;
}
