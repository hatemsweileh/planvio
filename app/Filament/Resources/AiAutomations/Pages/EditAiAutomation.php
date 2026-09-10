<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiAutomations\Pages;

use App\Filament\Resources\AiAutomations\AiAutomationResource;
use App\Filament\Support\AdminAudit;
use App\Models\AiAutomation;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditAiAutomation extends EditRecord
{
    protected static string $resource = AiAutomationResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription(__('The automation stops. Runs it has already produced stay in the AI run log.')),
        ];
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof AiAutomation) {
            return;
        }

        $changed = array_values(array_diff(array_keys($record->getChanges()), ['updated_at']));

        // Recalculated unconditionally rather than only when the expression changed: switching
        // the automation back on, or moving it to another workspace and therefore another
        // timezone, both change when it is next due.
        AiAutomationResource::scheduleNextRun($record);

        if ($changed === []) {
            return;
        }

        AdminAudit::record(
            'admin.ai_automation_updated',
            __('AI automation changed from the administration panel.'),
            properties: [
                'automation_id' => (int) $record->getKey(),
                'automation' => (string) $record->name,
                'fields' => $changed,
            ],
            workspaceId: (int) $record->workspace_id,
        );
    }
}
