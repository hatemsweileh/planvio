<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named grouping of workspace members. Teams carry no permissions of their own — they
 * are an addressing convenience for assignment and reporting.
 */
final class Team extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'description',
        'color',
    ];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')
            ->withPivot('is_lead')
            ->withTimestamps();
    }

    /**
     * @return HasMany<TeamMember, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function leads(): BelongsToMany
    {
        return $this->members()->wherePivot('is_lead', true);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForMember(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return $query->whereHas('memberships', fn (Builder $sub): Builder => $sub->where('user_id', $userId));
    }
}
