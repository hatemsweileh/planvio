<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\Pages;

use App\Filament\Resources\Settings\SettingResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSettings extends ListRecords
{
    protected static string $resource = SettingResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            SettingResource::flushCacheAction(),
            CreateAction::make()->label(__('New setting')),
        ];
    }

    public function getSubheading(): ?string
    {
        return __('Direct access to the key/value store. Most configuration has a screen of its own; use this when nothing else can reach the value.');
    }
}
