<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Str;

/**
 * Fans one domain event out to every endpoint subscribed to it.
 *
 * Registered against every event {@see WebhookEvent} knows how to describe, which is why the
 * handler takes a bare `object`: the mapping table lives in one place and this class stays a
 * pipe. An event with no mapping is dropped here rather than throwing, so adding a listener
 * registration before adding its payload is harmless.
 *
 * The lookup deliberately escapes the workspace scope and filters by `workspace_id`
 * explicitly. Events are also raised from console commands and queue workers, where nothing
 * is bound and the scope is inert (ARCHITECTURE.md §3) — relying on it would mean a webhook
 * fired from the scheduler quietly reached every tenant's endpoints.
 *
 * One job per endpoint. A single job looping over endpoints would let one slow receiver
 * delay the rest, and one failing receiver retry all of them.
 */
final class DispatchWebhooks
{
    public function __construct(private readonly BusDispatcher $bus) {}

    public function handle(object $event): void
    {
        $described = WebhookEvent::from($event);

        if ($described === null) {
            return;
        }

        $webhooks = Webhook::withoutWorkspaceScope()
            ->where('workspace_id', $described->workspaceId)
            ->active()
            ->forEvent($described->name)
            ->get();

        if ($webhooks->isEmpty()) {
            return;
        }

        // Built once: every endpoint receives byte-identical content, which is what makes a
        // receiver able to deduplicate on the payload rather than on delivery order.
        $body = $described->body();

        foreach ($webhooks as $webhook) {
            $this->bus->dispatch(new DeliverWebhook(
                webhookId: (int) $webhook->getKey(),
                event: $described->name,
                payload: $body,
                deliveryId: (string) Str::uuid(),
            ));
        }
    }
}
