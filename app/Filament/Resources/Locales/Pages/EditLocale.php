<?php

declare(strict_types=1);

namespace App\Filament\Resources\Locales\Pages;

use App\Filament\Resources\Locales\LocaleResource;
use App\Filament\Support\AdminAudit;
use App\Models\Locale;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing a language, with the two invariants enforced on the way through.
 *
 * The form already disables the controls that would break them, and that is not enough: a
 * disabled input is a courtesy to the person, not a constraint on the request. Both are
 * therefore re-applied to the payload here, where nothing can go round them.
 */
final class EditLocale extends EditRecord
{
    protected static string $resource = LocaleResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => ($record = $this->getRecord()) instanceof Locale && ! $record->is_default)
                ->modalDescription(__('Every translated line stored for this language is deleted with it and cannot be recovered. Export it first if there is any chance you want it back. Accounts and workspaces set to it are moved to the default.'))
                ->before(function (): void {
                    $record = $this->getRecord();

                    if (! $record instanceof Locale) {
                        return;
                    }

                    LocaleResource::fallBack($record);
                    LocaleResource::purgeTranslations($record);

                    AdminAudit::record(
                        'admin.locale_deleted',
                        __('Language removed from the administration panel.'),
                        properties: ['locale' => (string) $record->code],
                    );
                }),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        // The default is where every fallback lands. A payload that would switch it off, or
        // clear the flag without naming a replacement, is corrected rather than refused: the
        // form offers neither, so anything arriving here is a hand-made request.
        if ($record instanceof Locale && $record->is_default) {
            $data['is_enabled'] = true;
            $data['is_default'] = true;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Locale) {
            return;
        }

        LocaleResource::enforceSingleDefault($record);
        LocaleResource::forgetStatistics();

        // Asked of the row rather than of the form: `wasChanged` is the one answer that
        // accounts for the payload having been corrected on the way past.
        $moved = $record->wasChanged('is_enabled') && ! $record->is_enabled
            ? LocaleResource::fallBack($record)
            : ['users' => 0, 'workspaces' => 0];

        $changed = array_values(array_diff(array_keys($record->getChanges()), ['updated_at']));

        if ($changed === []) {
            return;
        }

        AdminAudit::record(
            'admin.locale_updated',
            __('Language updated from the administration panel.'),
            properties: [
                'locale' => (string) $record->code,
                'fields' => $changed,
            ] + $moved,
        );
    }
}
