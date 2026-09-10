<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Settings;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single row of the platform key/value store.
 *
 * Storage layer only. Typed reads and writes — caching, JSON encoding, and the encryption of
 * secret values — belong to {@see Settings}, which talks to this table through
 * the query builder so it stays usable before migrations have run. Nothing here duplicates it.
 *
 * `value` holds JSON; for a row with `is_encrypted` set, that JSON is the ciphertext string,
 * so reading the attribute directly never yields a plaintext secret.
 */
final class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'key',
        'value',
        'is_encrypted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'is_encrypted' => 'boolean',
        ];
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeWithKey(Builder $query, string $key): Builder
    {
        return $query->where($this->qualifyColumn('key'), $key);
    }

    /**
     * @param Builder<static> $query
     * @param array<int, string> $keys
     * @return Builder<static>
     */
    public function scopeWithKeys(Builder $query, array $keys): Builder
    {
        return $query->whereIn($this->qualifyColumn('key'), $keys);
    }

    /**
     * Rows whose key sits under a dotted namespace, e.g. `mail.`.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeInGroup(Builder $query, string $prefix): Builder
    {
        return $query->where($this->qualifyColumn('key'), 'like', $prefix.'%');
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeEncrypted(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_encrypted'), true);
    }
}
