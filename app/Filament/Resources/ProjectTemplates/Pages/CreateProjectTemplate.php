<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectTemplates\Pages;

use App\Filament\Resources\ProjectTemplates\ProjectTemplateResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateProjectTemplate extends CreateRecord
{
    protected static string $resource = ProjectTemplateResource::class;

    /**
     * A new template starts from the shape `CreateProjectFromTemplate` reads, rather than an
     * empty object somebody has to reverse-engineer from the code.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['definition'] ??= [
            'statuses' => [],
            'milestones' => [],
            'tasks' => [],
            'tags' => [],
            'views' => [],
        ];

        return $data;
    }
}
