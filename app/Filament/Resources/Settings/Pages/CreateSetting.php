<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\Pages;

use App\Filament\Resources\Settings\SettingResource;
use App\Filament\Support\AdminAudit;
use App\Models\Setting;
use App\Support\Settings;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateSetting extends CreateRecord
{
    protected static string $resource = SettingResource::class;

    /**
     * Written through {@see Settings} rather than Eloquent, so both cache layers are
     * invalidated with the row. See the note on SettingResource.
     *
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $key = (string) ($data['key'] ?? '');
        $encrypted = (bool) ($data['is_encrypted'] ?? false);

        app(Settings::class)->set(
            $key,
            SettingResource::decodeValue($data['value'] ?? null),
            $encrypted,
        );

        AdminAudit::record(
            'admin.setting_created',
            __('Setting created from the administration panel.'),
            // The key and whether it is a secret, never the value.
            properties: ['key' => $key, 'encrypted' => $encrypted],
        );

        return Setting::query()->where('key', $key)->firstOrFail();
    }
}
