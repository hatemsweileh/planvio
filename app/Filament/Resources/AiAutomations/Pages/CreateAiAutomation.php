<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiAutomations\Pages;

use App\Filament\Resources\AiAutomations\AiAutomationResource;
use App\Filament\Support\AdminAudit;
use App\Models\AiAutomation;
use Filament\Resources\Pages\CreateRecord;

final class CreateAiAutomation extends CreateRecord
{
    protected static string $resource = AiAutomationResource::class;

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof AiAutomation) {
            return;
        }

        // Without this the row looks scheduled and never fires: the runner selects on
        // `next_run_at`, and a fresh automation has none until the expression is read.
        AiAutomationResource::scheduleNextRun($record);

        AdminAudit::record(
            'admin.ai_automation_created',
            __('AI automation created from the administration panel.'),
            properties: [
                'automation_id' => (int) $record->getKey(),
                'automation' => (string) $record->name,
                'mode' => $record->mode?->value,
                'trigger' => $record->trigger_type?->value,
                'runs_as_user_id' => (int) $record->created_by,
            ],
            workspaceId: (int) $record->workspace_id,
        );
    }
}
