<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\AdminAudit;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

final class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * An account created here is verified on the spot.
     *
     * The alternative is an unverified account that cannot be used until somebody clicks a
     * link in a mailbox the administrator does not control. On a self-hosted installation the
     * administrator creating the account *is* the verification: they typed the address.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['email_verified_at'] = now();

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof User) {
            return;
        }

        AdminAudit::record(
            'admin.user_created',
            __('Account created from the administration panel.'),
            $record,
            ['is_admin' => (bool) $record->is_admin],
        );
    }
}
