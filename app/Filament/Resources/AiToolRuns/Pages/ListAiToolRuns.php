<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiToolRuns\Pages;

use App\Filament\Resources\AiToolRuns\AiToolRunResource;
use Filament\Resources\Pages\ListRecords;

final class ListAiToolRuns extends ListRecords
{
    protected static string $resource = AiToolRunResource::class;

    public function getSubheading(): ?string
    {
        return __('Read-only. Every mutating call the agent made, with the human who approved it where one was required.');
    }
}
