<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\WebhookFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An outbound HTTP subscription owned by a workspace.
 *
 * `secret` is the HMAC signing key for the payload, so it must stay readable by the dispatcher
 * — but it is hidden from array/JSON output so it cannot leak through an API resource or a log.
 */
final class Webhook extends Model
{
    /** @use HasFactory<WebhookFactory> */
    use BelongsToWorkspace;

    use HasFactory;

    /** Subscribing to this event name receives every event. */
    public const EVENT_WILDCARD = '*';

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'name',
        'url',
        'secret',
        'events',
        'is_active',
        'last_delivered_at',
        'failure_count',
    ];

    /** @var list<string> */
    protected $hidden = [
        'secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'last_delivered_at' => 'datetime',
            'failure_count' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------- *
     * Relations
     * ---------------------------------------------------------------- */

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /* ---------------------------------------------------------------- *
     * Derived reads
     * ---------------------------------------------------------------- */

    /**
     * @return array<int, string>
     */
    public function subscribedEvents(): array
    {
        $events = $this->events;

        if (! is_array($events)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $event): string => (string) $event, $events));
    }

    public function listensTo(string $event): bool
    {
        $events = $this->subscribedEvents();

        return in_array(self::EVENT_WILDCARD, $events, true) || in_array($event, $events, true);
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * Endpoints subscribed to $event, wildcard subscriptions included.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForEvent(Builder $query, string $event): Builder
    {
        return $query->where(function (Builder $subscribed) use ($event): void {
            $subscribed
                ->whereJsonContains($this->qualifyColumn('events'), $event)
                ->orWhereJsonContains($this->qualifyColumn('events'), self::EVENT_WILDCARD);
        });
    }

    /**
     * Endpoints that have failed at least $threshold times in a row.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeFailing(Builder $query, int $threshold = 1): Builder
    {
        return $query->where($this->qualifyColumn('failure_count'), '>=', $threshold);
    }
}
