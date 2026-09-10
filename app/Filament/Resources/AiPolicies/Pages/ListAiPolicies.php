<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPolicies\Pages;

use App\Filament\Resources\AiPolicies\AiPolicyResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAiPolicies extends ListRecords
{
    protected static string $resource = AiPolicyResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New policy')),
        ];
    }
}
