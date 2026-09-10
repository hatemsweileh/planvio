<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\SecretScrubber;
use App\Support\Version;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Posts one event to one endpoint, signed, with retries and a record of every attempt.
 *
 * **Signing.** `X-Planvio-Signature` carries `t=<unix>,v1=<hex>` where the HMAC-SHA256 is
 * taken over `"<timestamp>.<raw body>"` — not over the body alone. Signing the body alone
 * would let anyone who captured a request replay it forever: the signature would still
 * verify, and a receiver would have nothing to check freshness against. Binding the
 * timestamp into the MAC means it cannot be moved forward without breaking the signature,
 * so a receiver that rejects timestamps older than `tolerance_seconds` is genuinely
 * replay-proof.
 *
 * The body is serialised once, here, and both signed and sent as that exact string. Signing
 * a re-encoded copy is the classic way to produce signatures that verify on the sender and
 * fail on the receiver, because JSON encoding is not canonical.
 *
 * **Failure accounting.** `webhooks.failure_count` counts consecutive failed *deliveries*,
 * not attempts, so it is incremented once when a job gives up rather than once per retry —
 * otherwise a threshold of fifteen would trip after four events. An endpoint that reaches
 * the configured threshold is switched off: a dead URL on a busy workspace is otherwise an
 * unbounded stream of jobs nobody is watching, and turning it off is visible and reversible
 * in the UI where silently dropping deliveries is neither.
 *
 * The secret never leaves this class. It is not logged, not stored on the delivery row, and
 * `Webhook::$hidden` keeps it out of any array or JSON representation.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** `webhook_deliveries.attempt` is a tinyint. */
    private const MAX_RECORDED_ATTEMPT = 127;

    /**
     * @param array<string, mixed> $payload the exact body to send, built by WebhookEvent
     */
    public function __construct(
        private readonly int $webhookId,
        private readonly string $event,
        private readonly array $payload,
        private readonly string $deliveryId,
    ) {}

    public function tries(): int
    {
        return max(1, (int) config('planvio.webhooks.tries', 4));
    }

    /**
     * Seconds to wait before each retry. Laravel repeats the last value when it runs out.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        $backoff = config('planvio.webhooks.backoff');

        if (! is_array($backoff) || $backoff === []) {
            return [60, 300, 900];
        }

        return array_values(array_map(static fn (mixed $seconds): int => max(1, (int) $seconds), $backoff));
    }

    public function handle(): void
    {
        // System code running with no tenant bound: the scope would be inert here anyway,
        // and escaping it explicitly says so rather than depending on it (§3).
        $webhook = Webhook::withoutWorkspaceScope()->find($this->webhookId);

        if (! $webhook instanceof Webhook || ! $webhook->is_active) {
            return;
        }

        if (! $this->isDeliverable((string) $webhook->url)) {
            $this->disable($webhook);
            $this->record($webhook, null, __('The endpoint URL is not an http or https address.'));

            return;
        }

        try {
            $body = $this->encode();
        } catch (JsonException $exception) {
            // The payload cannot be represented, so no number of retries will help.
            $this->record($webhook, null, 'Payload could not be encoded: '.$exception->getMessage());
            $this->fail($exception);

            return;
        }

        $timestamp = Carbon::now()->getTimestamp();

        try {
            $response = Http::withHeaders($this->headers($webhook, $body, $timestamp))
                ->withBody($body, 'application/json')
                ->connectTimeout((int) config('planvio.webhooks.connect_timeout', 5))
                ->timeout((int) config('planvio.webhooks.timeout', 10))
                ->post((string) $webhook->url);

            $status = $response->status();
            $responseBody = $response->body();
        } catch (ConnectionException $exception) {
            // Unreachable host, TLS failure, timeout. A null status is how the delivery row
            // records "we never got an answer" as distinct from "we got a bad one".
            $status = null;
            $responseBody = $exception->getMessage();
        }

        $delivery = $this->record($webhook, $status, $responseBody);

        if ($delivery->wasAccepted()) {
            $this->succeed($webhook);

            return;
        }

        $this->retryOrGiveUp($webhook, $status);
    }

    /**
     * The queue has exhausted the retries, or the job was failed outright.
     */
    public function failed(?Throwable $exception): void
    {
        $webhook = Webhook::withoutWorkspaceScope()->find($this->webhookId);

        if ($webhook instanceof Webhook) {
            $this->registerFailure($webhook);
        }
    }

    /**
     * Queue tags, for a dashboard that groups deliveries by endpoint.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return ['webhook:'.$this->webhookId, 'event:'.$this->event];
    }

    /**
     * @throws JsonException
     */
    private function encode(): string
    {
        return json_encode(
            $this->payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(Webhook $webhook, string $body, int $timestamp): array
    {
        $names = config('planvio.webhooks.headers');
        $names = is_array($names) ? $names : [];

        $signature = hash_hmac('sha256', $timestamp.'.'.$body, (string) $webhook->secret);

        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Planvio/'.Version::app(),
            (string) ($names['signature'] ?? 'X-Planvio-Signature') => 't='.$timestamp.',v1='.$signature,
            (string) ($names['timestamp'] ?? 'X-Planvio-Timestamp') => (string) $timestamp,
            (string) ($names['event'] ?? 'X-Planvio-Event') => $this->event,
            (string) ($names['delivery'] ?? 'X-Planvio-Delivery') => $this->deliveryId,
            'X-Planvio-Attempt' => (string) $this->attemptNumber(),
        ];
    }

    private function record(Webhook $webhook, ?int $status, ?string $responseBody): WebhookDelivery
    {
        return WebhookDelivery::query()->create([
            'webhook_id' => $webhook->getKey(),
            'event' => $this->event,
            'payload' => $this->payload,
            'response_status' => $status,
            'response_body' => $this->truncate($responseBody),
            'attempt' => $this->attemptNumber(),
            'delivered_at' => Carbon::now(),
        ]);
    }

    private function succeed(Webhook $webhook): void
    {
        $webhook->forceFill([
            'failure_count' => 0,
            'last_delivered_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Retry while attempts remain; otherwise stop and account for the failed delivery.
     */
    private function retryOrGiveUp(Webhook $webhook, ?int $status): void
    {
        if ($this->attemptNumber() < $this->tries() && $this->job !== null) {
            $this->release($this->delayForNextAttempt());

            return;
        }

        $this->registerFailure($webhook);

        Log::warning('Webhook delivery gave up.', [
            'webhook_id' => (int) $webhook->getKey(),
            'event' => $this->event,
            'delivery_id' => $this->deliveryId,
            'response_status' => $status,
        ]);
    }

    /**
     * One more consecutive failed delivery, and the endpoint off if that was one too many.
     */
    private function registerFailure(Webhook $webhook): void
    {
        $webhook->increment('failure_count');
        $webhook->refresh();

        $threshold = (int) config('planvio.webhooks.disable_after_failures', 15);

        if ($threshold > 0 && (int) $webhook->failure_count >= $threshold) {
            $this->disable($webhook);
        }
    }

    private function disable(Webhook $webhook): void
    {
        if (! $webhook->is_active) {
            return;
        }

        $webhook->forceFill(['is_active' => false])->save();

        Log::warning('Webhook disabled after repeated failures.', [
            'webhook_id' => (int) $webhook->getKey(),
            'workspace_id' => (int) $webhook->workspace_id,
            'failure_count' => (int) $webhook->failure_count,
        ]);
    }

    private function delayForNextAttempt(): int
    {
        $backoff = $this->backoff();
        $index = min($this->attemptNumber() - 1, count($backoff) - 1);

        return $backoff[max($index, 0)];
    }

    private function attemptNumber(): int
    {
        return min(max($this->attempts(), 1), self::MAX_RECORDED_ATTEMPT);
    }

    /**
     * The stored body comes from somewhere we do not control.
     *
     * A hostile or merely careless endpoint can echo our request back — including the
     * X-Planvio-Signature header — and a ConnectionException message can quote a URL
     * carrying userinfo. Either would then sit in webhook_deliveries.response_body for
     * anyone with reports access to read, which is exactly the leak the AI layer's audit
     * caught on its own audit rows.
     *
     * Scrub first, cut second: truncating first can split a credential so that neither
     * half matches a pattern, leaving a recognisable prefix behind.
     */
    private function truncate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = app(SecretScrubber::class)->scrub($value);

        $limit = max(0, (int) config('planvio.webhooks.max_response_bytes', 2048));

        return mb_strcut($value, 0, $limit);
    }

    /**
     * Only http(s) is delivered to. A `file://` or `gopher://` endpoint is not a webhook,
     * it is a way to make the server read something on somebody's behalf.
     */
    private function isDeliverable(string $url): bool
    {
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST) !== null;
    }
}
