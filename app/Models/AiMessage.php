<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiMessageRole;
use Database\Factories\AiMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn in an {@see AiConversation}: a system, user, assistant or tool message.
 *
 * Tenant-scoped transitively through the conversation, which is why the table carries no
 * `workspace_id` of its own.
 */
final class AiMessage extends Model
{
    /** @use HasFactory<AiMessageFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'ai_conversation_id',
        'role',
        'content',
        'tool_calls',
        'tool_call_id',
        'name',
        'ai_run_id',
        'tokens_in',
        'tokens_out',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AiMessageRole::class,
            'tool_calls' => 'array',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------- *
     * Relations
     * ---------------------------------------------------------------- */

    /**
     * @return BelongsTo<AiConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    /**
     * @return BelongsTo<AiRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    /* ---------------------------------------------------------------- *
     * Derived reads
     * ---------------------------------------------------------------- */

    public function hasToolCalls(): bool
    {
        return is_array($this->tool_calls) && $this->tool_calls !== [];
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForConversation(Builder $query, AiConversation|int $conversation): Builder
    {
        return $query->where(
            $this->qualifyColumn('ai_conversation_id'),
            $conversation instanceof AiConversation ? $conversation->getKey() : $conversation,
        );
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOfRole(Builder $query, AiMessageRole $role): Builder
    {
        return $query->where($this->qualifyColumn('role'), $role->value);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForRun(Builder $query, AiRun|int $run): Builder
    {
        return $query->where(
            $this->qualifyColumn('ai_run_id'),
            $run instanceof AiRun ? $run->getKey() : $run,
        );
    }

    /**
     * Oldest first — the order the provider must receive them in.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy($this->qualifyColumn('id'));
    }
}
