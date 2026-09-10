<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookDeliveries\Pages;

use App\Filament\Resources\WebhookDeliveries\WebhookDeliveryResource;
use Filament\Resources\Pages\ListRecords;

final class ListWebhookDeliveries extends ListRecords
{
    protected static string $resource = WebhookDeliveryResource::class;

    public function getSubheading(): ?string
    {
        $days = config('planvio.retention.webhook_delivery_days');

        return is_int($days) && $days > 0
            ? __('Read-only. Deliveries older than :days days are removed by the nightly prune.', ['days' => $days])
            : __('Read-only.');
    }
}
