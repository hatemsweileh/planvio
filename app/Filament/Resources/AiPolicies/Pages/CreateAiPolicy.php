<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPolicies\Pages;

use App\Filament\Resources\AiPolicies\AiPolicyResource;
use App\Filament\Support\AdminAudit;
use App\Models\AiPolicy;
use Filament\Resources\Pages\CreateRecord;

final class CreateAiPolicy extends CreateRecord
{
    protected static string $resource = AiPolicyResource::class;

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof AiPolicy) {
            return;
        }

        AdminAudit::record(
            'admin.ai_policy_created',
            __('AI policy created from the administration panel.'),
            properties: [
                'policy_id' => (int) $record->getKey(),
                'policy' => (string) $record->name,
                'mode' => $record->mode?->value,
                'max_risk' => $record->max_risk?->value,
            ],
            workspaceId: $record->workspace_id === null ? null : (int) $record->workspace_id,
        );
    }
}
