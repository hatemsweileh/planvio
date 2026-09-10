<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiRuns\Pages;

use App\Filament\Resources\AiRuns\AiRunResource;
use Filament\Resources\Pages\ListRecords;

final class ListAiRuns extends ListRecords
{
    protected static string $resource = AiRunResource::class;

    public function getSubheading(): ?string
    {
        return __('Read-only. Runs are pruned nightly according to each workspace\'s AI retention window; a workspace that keeps everything keeps everything.');
    }
}
