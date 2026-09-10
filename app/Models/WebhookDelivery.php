<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to deliver one event to one {@see Webhook}.
 *
 * Tenant-scoped transitively through the webhook, which is why the table carries no
 * `workspace_id` of its own.
 */
final class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'webhook_id',
        'event',
        'payload',
        'response_status',
        'response_body',
        'attempt',
        'delivered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response_status' => 'integer',
            'attempt' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------- *
     * Relations
     * ---------------------------------------------------------------- */

    /**
     * @return BelongsTo<Webhook, $this>
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    /* ---------------------------------------------------------------- *
     * Derived reads
     * ---------------------------------------------------------------- */

    public function wasAccepted(): bool
    {
        $status = $this->response_status;

        return $status !== null && $status >= 200 && $status < 300;
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->whereBetween($this->qualifyColumn('response_status'), [200, 299]);
    }

    /**
     * Anything the endpoint did not accept, an unreachable host (null status) included.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where(function (Builder $failed): void {
            $failed
                ->whereNull($this->qualifyColumn('response_status'))
                ->orWhereNotBetween($this->qualifyColumn('response_status'), [200, 299]);
        });
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForEvent(Builder $query, string $event): Builder
    {
        return $query->where($this->qualifyColumn('event'), $event);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForWebhook(Builder $query, Webhook|int $webhook): Builder
    {
        return $query->where(
            $this->qualifyColumn('webhook_id'),
            $webhook instanceof Webhook ? $webhook->getKey() : $webhook,
        );
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query
            ->orderByDesc($this->qualifyColumn('created_at'))
            ->orderByDesc($this->qualifyColumn('id'));
    }
}
