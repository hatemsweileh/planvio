<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPolicies\Pages;

use App\Filament\Resources\AiPolicies\AiPolicyResource;
use App\Filament\Support\AdminAudit;
use App\Models\AiPolicy;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditAiPolicy extends EditRecord
{
    protected static string $resource = AiPolicyResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof AiPolicy) {
            return;
        }

        $changed = array_values(array_diff(array_keys($record->getChanges()), ['updated_at']));

        if ($changed === []) {
            return;
        }

        AdminAudit::record(
            'admin.ai_policy_updated',
            __('AI policy changed from the administration panel.'),
            properties: [
                'policy_id' => (int) $record->getKey(),
                'policy' => (string) $record->name,
                'fields' => $changed,
            ],
            workspaceId: $record->workspace_id === null ? null : (int) $record->workspace_id,
        );
    }
}
