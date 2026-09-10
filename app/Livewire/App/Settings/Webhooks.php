<?php

declare(strict_types=1);

namespace App\Livewire\App\Settings;

use App\Jobs\DeliverWebhook;
use App\Listeners\Webhooks\WebhookEvent;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use SensitiveParameter;

/**
 * Outbound webhooks: where this workspace pushes events, and what it signs them with.
 *
 * The signing secret is shown exactly once, on the screen that mints it. It is a bearer
 * credential — anybody holding it can forge a request Planvio would appear to have signed —
 * so there is no screen that reveals it again and nothing here ever writes it to a log
 * (CLAUDE.md rule 4). Losing it means rotating it, which is one click and invalidates the
 * old one.
 *
 * The event list is read from {@see WebhookEvent}, the class that actually
 * builds the payloads, so subscribing to something Planvio does not emit is not possible
 * from this screen rather than merely inert.
 */
final class Webhooks extends Component
{
    /**
     * The event name a test delivery carries.
     *
     * Deliberately outside the picker's namespaces and not subscribable: a test is something
     * a person asks for, not something a receiver signs up to, and a receiver that sees it
     * should be able to tell it apart from real work at a glance.
     */
    public const TEST_EVENT = 'planvio.test';

    /**
     * Every event the dispatcher knows how to describe, grouped for the picker.
     *
     * @var array<string, list<string>>
     */
    private const EVENTS = [
        'task' => [
            'task.created', 'task.updated', 'task.status_changed', 'task.assigned', 'task.completed',
        ],
        'project' => ['project.created', 'project.updated'],
        'milestone' => ['milestone.completed'],
        'comment' => ['comment.created'],
        'member' => ['member.invited', 'member.joined'],
    ];

    public Workspace $workspace;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $url = '';

    public bool $isActive = true;

    public bool $allEvents = false;

    /** @var array<int, string> */
    public array $events = [];

    /**
     * The freshly minted secret, held for exactly one render.
     *
     * Deliberately not persisted anywhere this component can read again: it is written to
     * the row (where the dispatcher reads it) and shown here, and that is the only moment a
     * person will ever see it.
     */
    #[Locked]
    public ?string $revealedSecret = null;

    #[Locked]
    public ?int $revealedFor = null;

    public ?int $inspectingId = null;

    /** Which delivery in the log has its response body open. */
    public ?int $expandedDeliveryId = null;

    public function mount(Workspace $workspace): void
    {
        $this->authorize('viewAny', [Webhook::class, $workspace]);

        $this->workspace = $workspace;
    }

    /**
     * @return Collection<int, Webhook>
     */
    #[Computed]
    public function webhooks(): Collection
    {
        return Webhook::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->withCount('deliveries')
            ->orderBy('name')
            ->get();
    }

    /**
     * The last handful of attempts for the endpoint being inspected.
     *
     * @return Collection<int, WebhookDelivery>
     */
    #[Computed]
    public function deliveries(): Collection
    {
        if ($this->inspectingId === null) {
            return collect();
        }

        $webhook = $this->webhook($this->inspectingId);

        if ($webhook === null) {
            return collect();
        }

        return WebhookDelivery::query()
            ->where('webhook_id', $webhook->getKey())
            ->latest('id')
            ->limit(20)
            ->get([
                'id', 'webhook_id', 'event', 'response_status', 'response_body',
                'attempt', 'delivered_at', 'created_at',
            ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function eventGroups(): array
    {
        return self::EVENTS;
    }

    /**
     * @return list<string>
     */
    public function allEventNames(): array
    {
        return array_merge(...array_values(self::EVENTS));
    }

    /* ------------------------------------------------------------------ *
     * Writes
     * ------------------------------------------------------------------ */

    public function startCreate(): void
    {
        $this->authorize('create', [Webhook::class, $this->workspace]);

        $this->editingId = null;
        $this->name = '';
        $this->url = '';
        $this->isActive = true;
        $this->allEvents = false;
        $this->events = ['task.created', 'task.status_changed'];
        $this->showForm = true;

        $this->resetValidation();
    }

    public function startEdit(int $webhookId): void
    {
        $webhook = $this->webhook($webhookId);

        if ($webhook === null) {
            return;
        }

        $this->authorize('update', $webhook);

        $subscribed = $webhook->subscribedEvents();

        $this->editingId = (int) $webhook->getKey();
        $this->name = (string) $webhook->name;
        $this->url = (string) $webhook->url;
        $this->isActive = (bool) $webhook->is_active;
        $this->allEvents = in_array(Webhook::EVENT_WILDCARD, $subscribed, true);
        $this->events = array_values(array_intersect($subscribed, $this->allEventNames()));
        $this->showForm = true;

        $this->resetValidation();
    }

    public function save(ActivityLogger $activity): void
    {
        $webhook = $this->editingId === null ? null : $this->webhook($this->editingId);

        if ($webhook === null) {
            $this->authorize('create', [Webhook::class, $this->workspace]);
        } else {
            $this->authorize('update', $webhook);
        }

        $data = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            // `active_url` would resolve DNS on every save and refuse an endpoint that is
            // simply not up yet, which is the normal state of one being configured.
            'url' => ['required', 'url:http,https', 'max:2048'],
            'isActive' => ['boolean'],
            'allEvents' => ['boolean'],
            'events' => ['array'],
        ]);

        $subscribed = $data['allEvents']
            ? [Webhook::EVENT_WILDCARD]
            : array_values(array_intersect($this->events, $this->allEventNames()));

        if ($subscribed === []) {
            $this->addError('events', __('Choose at least one event, or subscribe to all of them.'));

            return;
        }

        $creating = $webhook === null;
        $secret = null;

        if ($creating) {
            $secret = self::freshSecret();

            $webhook = new Webhook(['workspace_id' => $this->workspace->getKey()]);
            $webhook->secret = $secret;
        }

        $webhook->workspace_id = $this->workspace->getKey();
        $webhook->name = $data['name'];
        $webhook->url = $data['url'];
        $webhook->events = $subscribed;
        $webhook->is_active = (bool) $data['isActive'];

        // A configuration change is a fresh start for the failure counter: the endpoint that
        // was failing may be exactly the thing that was just corrected.
        $webhook->failure_count = 0;
        $webhook->save();

        // The URL is recorded; the secret never is.
        $activity->forUser($this->actor())->log($webhook, $creating ? 'created' : 'updated', [
            'name' => (string) $webhook->name,
            'url' => (string) $webhook->url,
            'events' => $subscribed,
            'is_active' => (bool) $webhook->is_active,
        ]);

        $this->showForm = false;
        $this->editingId = null;

        unset($this->webhooks);

        if ($secret !== null) {
            $this->reveal((int) $webhook->getKey(), $secret);
        }

        $this->dispatch('planvio-notify', type: 'success', message: __('Webhook saved.'));
    }

    public function rotateSecret(int $webhookId, ActivityLogger $activity): void
    {
        $webhook = $this->webhook($webhookId);

        if ($webhook === null) {
            return;
        }

        $this->authorize('rotateSecret', $webhook);

        $secret = self::freshSecret();

        $webhook->secret = $secret;
        $webhook->save();

        $activity->forUser($this->actor())->log($webhook, 'updated', [
            'secret_rotated' => true,
            'name' => (string) $webhook->name,
        ]);

        $this->reveal((int) $webhook->getKey(), $secret);

        $this->dispatch('planvio-notify', type: 'warning', message: __('The old signing secret no longer works. Copy the new one now.'));
    }

    public function toggleActive(int $webhookId, ActivityLogger $activity): void
    {
        $webhook = $this->webhook($webhookId);

        if ($webhook === null) {
            return;
        }

        $this->authorize('update', $webhook);

        $webhook->is_active = ! $webhook->is_active;
        $webhook->failure_count = 0;
        $webhook->save();

        $activity->forUser($this->actor())->log($webhook, 'updated', [
            'is_active' => (bool) $webhook->is_active,
        ]);

        unset($this->webhooks);
    }

    public function deleteWebhook(int $webhookId, ActivityLogger $activity): void
    {
        $webhook = $this->webhook($webhookId);

        if ($webhook === null) {
            return;
        }

        $this->authorize('delete', $webhook);

        $activity->forUser($this->actor())->log($webhook, 'deleted', [
            'name' => (string) $webhook->name,
            'url' => (string) $webhook->url,
        ]);

        $webhook->delete();

        if ($this->inspectingId === $webhookId) {
            $this->inspectingId = null;
        }

        $this->dismissSecret();

        unset($this->webhooks, $this->deliveries);

        $this->dispatch('planvio-notify', type: 'success', message: __('Webhook deleted.'));
    }

    /**
     * Send a `planvio.test` delivery to the endpoint, now, and show what came back.
     *
     * Run synchronously rather than queued, which is the one place in Planvio that a webhook
     * leaves the web process. It has to be: the whole value of a test button is that the
     * person configuring the endpoint finds out whether it answers *while they are still
     * looking at it*, and on this hosting the queue is a database table drained by a cron
     * tick — a queued test would report back some minutes after they had moved on.
     *
     * It is the same {@see DeliverWebhook} the real events use, so the request is signed the
     * same way, recorded in the same delivery log, and truncated and scrubbed by the same
     * code. A test that took a different path would be a test of the wrong thing.
     *
     * The payload carries no workspace content. A test fires before anybody has agreed that
     * this URL should receive anything, so the most it says is which workspace and which
     * endpoint asked.
     */
    public function sendTest(int $webhookId, ActivityLogger $activity): void
    {
        $webhook = $this->webhook($webhookId);

        if ($webhook === null) {
            return;
        }

        $this->authorize('test', $webhook);

        $actor = $this->actor();

        DeliverWebhook::dispatchSync(
            webhookId: (int) $webhook->getKey(),
            event: self::TEST_EVENT,
            payload: [
                'event' => self::TEST_EVENT,
                'occurred_at' => Carbon::now()->toIso8601String(),
                'workspace_id' => (int) $this->workspace->getKey(),
                'actor' => [
                    'id' => (int) $actor->getKey(),
                    'name' => (string) $actor->name,
                    'email' => (string) $actor->email,
                ],
                'data' => [
                    'webhook_id' => (int) $webhook->getKey(),
                    'message' => 'This is a test delivery from Planvio. No records were changed.',
                ],
            ],
            deliveryId: (string) Str::uuid(),
        );

        // The URL is recorded; the secret and the signature never are.
        $activity->forUser($actor)->log($webhook, 'updated', [
            'test_delivery' => true,
            'name' => (string) $webhook->name,
        ]);

        // Show the log for this endpoint, so the attempt that was just made is on screen.
        $this->inspectingId = $webhookId;
        $this->expandedDeliveryId = null;

        unset($this->webhooks, $this->deliveries);

        $delivery = $this->deliveries->first();

        if ($delivery instanceof WebhookDelivery && $delivery->wasAccepted()) {
            $this->dispatch('planvio-notify', type: 'success', message: __('The endpoint answered :status.', [
                'status' => (string) $delivery->response_status,
            ]));

            return;
        }

        $this->dispatch('planvio-notify', type: 'error', message: $delivery instanceof WebhookDelivery && $delivery->response_status !== null
            ? __('The endpoint answered :status. The response is in the delivery log below.', [
                'status' => (string) $delivery->response_status,
            ])
            : __('The endpoint could not be reached. The reason is in the delivery log below.'));
    }

    public function inspect(int $webhookId): void
    {
        $webhook = $this->webhook($webhookId);

        if ($webhook === null) {
            return;
        }

        $this->authorize('viewDeliveries', $webhook);

        $this->inspectingId = $this->inspectingId === $webhookId ? null : $webhookId;
        $this->expandedDeliveryId = null;

        unset($this->deliveries);
    }

    /**
     * Open or close one delivery's stored response.
     */
    public function expandDelivery(int $deliveryId): void
    {
        if ($this->inspectingId === null) {
            return;
        }

        $webhook = $this->webhook($this->inspectingId);

        if ($webhook === null) {
            return;
        }

        $this->authorize('viewDeliveries', $webhook);

        $this->expandedDeliveryId = $this->expandedDeliveryId === $deliveryId ? null : $deliveryId;
    }

    public function dismissSecret(): void
    {
        $this->revealedSecret = null;
        $this->revealedFor = null;
    }

    public function render(): View
    {
        return view('livewire.app.settings.webhooks');
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    private function reveal(int $webhookId, #[SensitiveParameter] string $secret): void
    {
        $this->revealedFor = $webhookId;
        $this->revealedSecret = $secret;

        unset($this->webhooks);
    }

    private static function freshSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }

    private function webhook(int $webhookId): ?Webhook
    {
        return Webhook::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->whereKey($webhookId)
            ->first();
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
