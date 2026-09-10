<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Support\AdminAudit;
use App\Models\AiProvider;
use Filament\Resources\Pages\CreateRecord;

final class CreateAiProvider extends CreateRecord
{
    protected static string $resource = AiProviderResource::class;

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof AiProvider) {
            return;
        }

        AiProviderResource::enforceSingleDefault($record);

        // Identity and configuration only. The credential is never described here, not even
        // by length or fingerprint (CLAUDE.md rule 4).
        AdminAudit::record(
            'admin.ai_provider_created',
            __('AI provider added from the administration panel.'),
            properties: [
                'provider_id' => (int) $record->getKey(),
                'provider' => (string) $record->name,
                'driver' => $record->driver?->value,
                'model' => (string) $record->model,
                'has_api_key' => $record->hasApiKey(),
            ],
        );
    }
}
