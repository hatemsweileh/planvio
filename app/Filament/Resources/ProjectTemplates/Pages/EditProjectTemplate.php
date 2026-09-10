<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectTemplates\Pages;

use App\Filament\Resources\ProjectTemplates\ProjectTemplateResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditProjectTemplate extends EditRecord
{
    protected static string $resource = ProjectTemplateResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription(__('Projects already created from this template are untouched — a template is copied at creation, not linked.')),
        ];
    }
}
