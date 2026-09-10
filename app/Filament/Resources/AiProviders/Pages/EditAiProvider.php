<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Support\AdminAudit;
use App\Models\AiProvider;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditAiProvider extends EditRecord
{
    protected static string $resource = AiProviderResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription(__('The stored credential is destroyed with the row. Workspaces pointing at this provider fall back to the default one.')),
        ];
    }

    /**
     * Belt and braces on top of `$hidden`: the credential is stripped from whatever is used to
     * populate the form, so no code path can put it in front of a browser.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['api_key']);

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof AiProvider) {
            return;
        }

        AiProviderResource::enforceSingleDefault($record);

        $changed = array_values(array_diff(array_keys($record->getChanges()), ['updated_at']));

        if ($changed === []) {
            return;
        }

        AdminAudit::record(
            'admin.ai_provider_updated',
            in_array('api_key', $changed, true)
                ? __('AI provider updated, including its credential, from the administration panel.')
                : __('AI provider updated from the administration panel.'),
            properties: [
                'provider_id' => (int) $record->getKey(),
                'provider' => (string) $record->name,
                // Column names only — `api_key` appearing here says the key was replaced and
                // nothing whatsoever about its value.
                'fields' => $changed,
            ],
        );
    }
}
