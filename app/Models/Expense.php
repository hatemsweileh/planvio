<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ExpenseFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A cost booked against a project (ARCHITECTURE.md §5.5).
 *
 * `amount` is decimal(15,2) and is cast as a decimal string, never a float: money that
 * round-trips through binary floating point stops adding up.
 */
final class Expense extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'project_id',
        'user_id',
        'amount',
        'currency',
        'category',
        'description',
        'incurred_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'incurred_on' => 'date',
        ];
    }

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForProject(Builder $query, Project|int $project): Builder
    {
        return $query->where(
            $this->qualifyColumn('project_id'),
            $project instanceof Project ? $project->getKey() : $project,
        );
    }

    /**
     * Expenses incurred inside the inclusive range, in the shape of
     * index(project_id, incurred_on).
     *
     * Expressed as a half-open interval rather than BETWEEN. MySQL stores a `date` column
     * as a bare date, SQLite stores whatever Eloquent's date cast writes — a full
     * "Y-m-d H:i:s" — so an inclusive upper bound would silently drop the last day of the
     * range under SQLite. The exclusive next-day bound is exact on both drivers.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeBetween(
        Builder $query,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
    ): Builder {
        return $query->where($this->qualifyColumn('incurred_on'), '>=', Carbon::parse($from)->toDateString())
            ->where($this->qualifyColumn('incurred_on'), '<', Carbon::parse($to)->addDay()->toDateString());
    }

    /**
     * @param Builder<static> $query
     * @param string|iterable<int, string> $categories
     * @return Builder<static>
     */
    public function scopeInCategory(Builder $query, string|iterable $categories): Builder
    {
        $values = [];

        foreach (is_iterable($categories) ? $categories : [$categories] as $category) {
            $values[] = (string) $category;
        }

        return $query->whereIn($this->qualifyColumn('category'), $values);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where(
            $this->qualifyColumn('user_id'),
            $user instanceof User ? $user->getKey() : $user,
        );
    }
}
