<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiAutomations\Pages;

use App\Filament\Resources\AiAutomations\AiAutomationResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAiAutomations extends ListRecords
{
    protected static string $resource = AiAutomationResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New automation')),
        ];
    }

    public function getSubheading(): ?string
    {
        return (bool) config('ai.automations.enabled', true)
            ? null
            : __('Automations are switched off for this installation (AI_AUTOMATIONS_ENABLED). Nothing below will run.');
    }
}
