<?php

declare(strict_types=1);

namespace Tests\Feature\App\Settings;

use App\Enums\WorkspaceRole;
use App\Livewire\App\Settings\Webhooks;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The webhooks screen, and the two things it has to get right.
 *
 * **The secret is shown once.** It is a bearer credential: whoever holds it can forge a
 * request Planvio would appear to have signed. So it exists in exactly one render, there is
 * no screen that reveals it again, and losing it means rotating it.
 *
 * **A test delivery is a real delivery.** It goes through the same job, signed the same way
 * and recorded in the same log — a test that took a shortcut would be a test of the shortcut.
 * It runs synchronously because the queue on this hosting is a cron-drained table, and a
 * queued test would report back long after the person configuring the endpoint had left.
 */
final class WebhooksTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);
        $this->admin = $this->makeMember($this->workspace, WorkspaceRole::Owner);
    }

    #[Test]
    public function it_creates_an_endpoint_and_shows_the_secret_once(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(Webhooks::class, ['workspace' => $this->workspace])
            ->call('startCreate')
            ->set('name', 'Build server')
            ->set('url', 'https://example.test/hooks/planvio')
            ->set('events', ['task.created'])
            ->call('save');

        $component->assertHasNoErrors();

        $webhook = Webhook::withoutWorkspaceScope()->firstOrFail();
        $secret = $component->get('revealedSecret');

        $this->assertIsString($secret);
        $this->assertSame($secret, $webhook->secret, 'The revealed secret is not the one that signs deliveries.');

        // Dismissed, and there is no way back to it.
        $component->call('dismissSecret')->assertSet('revealedSecret', null);

        Livewire::actingAs($this->admin)
            ->test(Webhooks::class, ['workspace' => $this->workspace])
            ->assertSet('revealedSecret', null)
            ->assertDontSee($secret);
    }

    #[Test]
    public function rotating_the_secret_invalidates_the_old_one(): void
    {
        $webhook = Webhook::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'secret' => 'whsec_original',
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(Webhooks::class, ['workspace' => $this->workspace])
            ->call('rotateSecret', $webhook->getKey());

        $rotated = (string) $component->get('revealedSecret');

        $this->assertNotSame('whsec_original', $rotated);
        $this->assertSame($rotated, $webhook->fresh()?->secret);
    }

    #[Test]
    public function a_test_delivery_is_signed_and_recorded(): void
    {
        Http::fake(['*' => Http::response('{"ok":true}', 200)]);

        $webhook = Webhook::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'url' => 'https://example.test/hooks/planvio',
            'secret' => 'whsec_known_signing_key',
            'is_active' => true,
            'failure_count' => 3,
        ]);

        Livewire::actingAs($this->admin)
            ->test(Webhooks::class, ['workspace' => $this->workspace])
            ->call('sendTest', $webhook->getKey())
            ->assertSet('inspectingId', $webhook->getKey())
            ->assertDispatched('planvio-notify');

        $delivery = WebhookDelivery::query()->firstOrFail();

        $this->assertSame(Webhooks::TEST_EVENT, $delivery->event);
        $this->assertSame(200, $delivery->response_status);
        $this->assertStringContainsString('ok', (string) $delivery->response_body);

        // A successful delivery clears the failure run and stamps the endpoint.
        $this->assertSame(0, (int) $webhook->fresh()?->failure_count);
        $this->assertNotNull($webhook->fresh()?->last_delivered_at);

        Http::assertSent(function (ClientRequest $request) use ($webhook): bool {
            $signature = $request->header('X-Planvio-Signature')[0] ?? '';
            $timestamp = $request->header('X-Planvio-Timestamp')[0] ?? '';

            // `t=<unix>,v1=<hmac>` over "<timestamp>.<raw body>" — the same algorithm
            // docs/API.md documents for receivers.
            $expected = hash_hmac('sha256', $timestamp.'.'.$request->body(), (string) $webhook->secret);

            return $request->url() === 'https://example.test/hooks/planvio'
                && $signature === 't='.$timestamp.',v1='.$expected
                && ($request->header('X-Planvio-Event')[0] ?? '') === Webhooks::TEST_EVENT;
        });
    }

    #[Test]
    public function a_test_delivery_carries_no_workspace_content(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $webhook = Webhook::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'url' => 'https://example.test/hooks/planvio',
            'secret' => 'whsec_known_signing_key',
        ]);

        Livewire::actingAs($this->admin)
            ->test(Webhooks::class, ['workspace' => $this->workspace])
            ->call('sendTest', $webhook->getKey());

        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->body();

            // A test fires before anybody has agreed this URL should receive anything, so the
            // most it says is which workspace and which endpoint asked. Above all, never the
            // signing secret.
            return ! str_contains($body, 'whsec_')
                && str_contains($body, '"workspace_id"');
        });
    }

    #[Test]
    public function a_failed_test_delivery_keeps_the_response_for_the_log(): void
    {
        Http::fake(['*' => Http::response('Bad signature header', 401)]);

        $webhook = Webhook::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'url' => 'https://example.test/hooks/planvio',
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(Webhooks::class, ['workspace' => $this->workspace])
            ->call('sendTest', $webhook->getKey());

        $delivery = WebhookDelivery::query()->firstOrFail();

        $this->assertSame(401, $delivery->response_status);
        $this->assertStringContainsString('Bad signature header', (string) $delivery->response_body);

        // The log is on screen with the response one click away.
        $component->call('expandDelivery', $delivery->getKey())
            ->assertSet('expandedDeliveryId', $delivery->getKey())
            ->assertSee('Bad signature header');

        $component->call('expandDelivery', $delivery->getKey())
            ->assertSet('expandedDeliveryId', null);
    }

    #[Test]
    public function the_screen_offers_the_test_and_the_delivery_log(): void
    {
        Webhook::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Build server',
            'url' => 'https://example.test/hooks/planvio',
        ]);

        Livewire::actingAs($this->admin)
            ->test(Webhooks::class, ['workspace' => $this->workspace])
            ->assertSee('Build server')
            ->assertSee('Send test delivery')
            ->assertSee('Recent deliveries')
            ->assertSee('Rotate signing secret');
    }

    #[Test]
    public function a_paused_endpoint_is_not_tested(): void
    {
        Http::fake();

        $webhook = Webhook::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'is_active' => false,
        ]);

        Livewire::actingAs($this->admin)
            ->test(Webhooks::class, ['workspace' => $this->workspace])
            ->call('sendTest', $webhook->getKey());

        Http::assertNothingSent();
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    #[Test]
    public function a_member_cannot_reach_the_screen_at_all(): void
    {
        $member = $this->makeMember($this->workspace, WorkspaceRole::Member);

        // `webhooks.manage` is owner and admin only (ARCHITECTURE.md §4.2).
        Livewire::actingAs($member)
            ->test(Webhooks::class, ['workspace' => $this->workspace])
            ->assertForbidden();
    }

    #[Test]
    public function an_endpoint_from_another_workspace_is_untouchable(): void
    {
        Http::fake();

        $other = $this->makeWorkspace(['slug' => 'northwind']);
        $foreign = Webhook::factory()->create(['workspace_id' => $other->getKey()]);

        Livewire::actingAs($this->admin)
            ->test(Webhooks::class, ['workspace' => $this->workspace])
            ->call('sendTest', $foreign->getKey())
            ->call('deleteWebhook', $foreign->getKey())
            ->call('rotateSecret', $foreign->getKey());

        Http::assertNothingSent();
        $this->assertDatabaseHas('webhooks', ['id' => $foreign->getKey()]);
        $this->assertSame($foreign->secret, $foreign->fresh()?->secret);
    }
}
