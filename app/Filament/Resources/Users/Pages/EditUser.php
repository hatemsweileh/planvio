<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\AdminAudit;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

final class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription(__('The account is deactivated and hidden everywhere. Its comments, logged time and audit entries are kept, and the account can be restored.'))
                ->visible(function (): bool {
                    $record = $this->getRecord();

                    return $record instanceof User
                        && ! UserResource::isSelf($record)
                        && ! UserResource::isLastAdministrator($record);
                }),
            RestoreAction::make(),
        ];
    }

    /**
     * `getChanges()` is read after the update, so it holds exactly the columns the save
     * actually wrote — a form resubmitted unchanged records nothing.
     */
    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof User) {
            return;
        }

        $changed = array_values(array_diff(
            array_keys($record->getChanges()),
            ['password', 'remember_token', 'updated_at'],
        ));

        if ($changed === []) {
            return;
        }

        AdminAudit::record(
            'admin.user_updated',
            __('Account updated from the administration panel.'),
            $record,
            // Column names only. A value here could be an email address changing hands, and
            // the diff is not what the trail is for — "who touched this account" is.
            ['fields' => $changed],
        );
    }
}
