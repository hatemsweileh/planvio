<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogs\Pages;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

final class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    public function getSubheading(): ?string
    {
        $days = config('planvio.retention.audit_days');

        return is_int($days) && $days > 0
            ? __('Read-only. Entries older than :days days are removed by the nightly prune.', ['days' => $days])
            : __('Read-only. Entries are kept indefinitely on this installation.');
    }
}
