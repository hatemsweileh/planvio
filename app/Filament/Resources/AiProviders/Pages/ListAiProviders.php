<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAiProviders extends ListRecords
{
    protected static string $resource = AiProviderResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add provider')),
        ];
    }

    public function getSubheading(): ?string
    {
        return (bool) config('ai.enabled', false)
            ? null
            : __('AI is switched off for this installation (AI_ENABLED). Providers can be configured, but nothing will call them.');
    }
}
