<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workspaces\Pages;

use App\Actions\Workspaces\UpdateWorkspace;
use App\Actions\Workspaces\WorkspaceAttributes;
use App\Filament\Resources\Workspaces\WorkspaceResource;
use App\Filament\Support\AdminAudit;
use App\Models\Workspace;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditWorkspace extends EditRecord
{
    protected static string $resource = WorkspaceResource::class;

    /**
     * The save goes through the domain action rather than straight at the model.
     *
     * `UpdateWorkspace` re-generates the slug against the uniqueness rule, records the change
     * on the workspace's own activity feed, and fires `WorkspaceUpdated`. A rename made here
     * should be indistinguishable from one the owner made themselves — the members of the
     * workspace are the people who need to see it happen.
     *
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Workspace) {
            return $record;
        }

        $updated = app(UpdateWorkspace::class)(
            $record,
            new WorkspaceAttributes(
                name: self::text($data, 'name'),
                slug: self::text($data, 'slug'),
                description: self::text($data, 'description') ?? '',
                accentColor: self::text($data, 'accent_color'),
                timezone: self::text($data, 'timezone'),
                locale: self::text($data, 'locale'),
                currency: self::text($data, 'currency'),
                dateFormat: self::text($data, 'date_format'),
                weekStartsOn: isset($data['week_starts_on']) ? (int) $data['week_starts_on'] : null,
            ),
            WorkspaceResource::administrator(),
        );

        AdminAudit::record(
            'admin.workspace_updated',
            __('Workspace settings changed from the administration panel.'),
            properties: ['workspace' => $updated->slug],
            workspaceId: (int) $updated->getKey(),
        );

        return $updated;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
