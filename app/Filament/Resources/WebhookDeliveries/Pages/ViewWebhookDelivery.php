<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookDeliveries\Pages;

use App\Filament\Resources\WebhookDeliveries\WebhookDeliveryResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewWebhookDelivery extends ViewRecord
{
    protected static string $resource = WebhookDeliveryResource::class;
}
