<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiRuns\Pages;

use App\Filament\Resources\AiRuns\AiRunResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

final class ViewAiRun extends ViewRecord
{
    protected static string $resource = AiRunResource::class;

    /**
     * The trace and everything it names are loaded here rather than in the resource query.
     *
     * `Model::preventLazyLoading()` is on outside production, so an infolist that reached for
     * `$record->toolRuns` would throw; loading it on the list query instead would drag every
     * tool run of every row on the page along with it.
     */
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load([
            'workspace',
            'user',
            'provider',
            'project' => AiRunResource::acrossWorkspaces(),
            'automation' => AiRunResource::acrossWorkspaces(),
            'toolRuns' => AiRunResource::acrossWorkspaces(),
            'toolRuns.approver',
        ]);
    }
}
