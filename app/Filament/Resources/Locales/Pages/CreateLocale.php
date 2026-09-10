<?php

declare(strict_types=1);

namespace App\Filament\Resources\Locales\Pages;

use App\Filament\Resources\Locales\LocaleResource;
use App\Filament\Support\AdminAudit;
use App\Models\Locale;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/**
 * Adding a language, and giving the translator something to open.
 *
 * The seed choice is not a column, so it is lifted out of the payload before the row is
 * written and applied after — a new `locales` row with no `translations` rows behind it would
 * open the editor on an empty list, which reads as a broken screen rather than as a new
 * language.
 */
final class CreateLocale extends CreateRecord
{
    protected static string $resource = LocaleResource::class;

    private ?string $seedFrom = null;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $seed = $data['seed_from'] ?? null;
        $this->seedFrom = is_string($seed) ? $seed : null;

        unset($data['seed_from']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Locale) {
            return;
        }

        LocaleResource::enforceSingleDefault($record);

        $seeded = LocaleResource::seed($record, $this->seedFrom);

        LocaleResource::forgetStatistics();

        AdminAudit::record(
            'admin.locale_created',
            __('Language added from the administration panel.'),
            properties: [
                'locale' => (string) $record->code,
                'direction' => (string) $record->direction,
                'is_enabled' => (bool) $record->is_enabled,
                'is_default' => (bool) $record->is_default,
                'seeded_keys' => $seeded['keys'],
                'copied_lines' => $seeded['copied'],
            ],
        );

        if ($seeded['keys'] === 0 && $seeded['copied'] === 0) {
            return;
        }

        Notification::make()
            ->title(__(':name is ready to translate.', ['name' => $record->name]))
            ->body(__(':keys keys are listed, :copied of them already carrying wording copied from another language. Nothing copied is marked reviewed.', [
                'keys' => number_format($seeded['keys']),
                'copied' => number_format($seeded['copied']),
            ]))
            ->success()
            ->send();
    }
}
