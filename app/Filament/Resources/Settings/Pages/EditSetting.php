<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\Pages;

use App\Filament\Resources\Settings\SettingResource;
use App\Filament\Support\AdminAudit;
use App\Models\Setting;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditSetting extends EditRecord
{
    protected static string $resource = SettingResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription(__('The application falls back to the default compiled into config/ for this key. If it has no default, whatever reads it gets null.'))
                ->using(function (Model $record): void {
                    if (! $record instanceof Setting) {
                        return;
                    }

                    // Through the facade so the cached entry — including the cached
                    // "this key does not exist" marker — goes with the row.
                    app(Settings::class)->forget((string) $record->key);

                    AdminAudit::record(
                        'admin.setting_deleted',
                        __('Setting deleted from the administration panel.'),
                        properties: ['key' => (string) $record->key],
                    );
                }),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Setting) {
            return $record;
        }

        $key = (string) $record->key;
        $wasEncrypted = (bool) $record->is_encrypted;
        $text = $data['value'] ?? null;
        $blank = ! is_string($text) || trim($text) === '';

        // An encrypted row whose editor was left empty means "keep the secret". Honouring the
        // encryption toggle here would mark existing ciphertext as plaintext and make the value
        // unreadable to everything that reads it — so nothing at all is written.
        if ($wasEncrypted && $blank) {
            Notification::make()
                ->title(__('The stored value was kept.'))
                ->body(__('Nothing was typed into the editor, so the encrypted value is unchanged.'))
                ->success()
                ->send();

            return $record->refresh();
        }

        $encrypted = (bool) ($data['is_encrypted'] ?? false);

        app(Settings::class)->set($key, SettingResource::decodeValue($text), $encrypted);

        AdminAudit::record(
            'admin.setting_updated',
            __('Setting changed from the administration panel.'),
            properties: ['key' => $key, 'encrypted' => $encrypted],
        );

        return $record->refresh();
    }
}
