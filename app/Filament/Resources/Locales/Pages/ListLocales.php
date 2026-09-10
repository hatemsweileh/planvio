<?php

declare(strict_types=1);

namespace App\Filament\Resources\Locales\Pages;

use App\Filament\Pages\Translations;
use App\Filament\Resources\Locales\LocaleResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListLocales extends ListRecords
{
    protected static string $resource = LocaleResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add language')),
            Action::make('recount')
                ->label(__('Recount'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (): void {
                    LocaleResource::statistics(fresh: true);

                    Notification::make()
                        ->title(__('Completion recalculated from the source.'))
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getSubheading(): ?string
    {
        return __('A language is offered only while it has a row here and is switched on. Wording is edited in :screen.', [
            'screen' => Translations::getNavigationLabel(),
        ]);
    }
}
