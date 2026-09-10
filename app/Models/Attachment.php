<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An uploaded file bound to any attachable subject (ARCHITECTURE.md §5.4).
 *
 * Files live on the private disk and are only ever reachable through the authorising
 * download route — never a disk URL. A public URL would hand out the file to anyone who
 * guessed the path, defeating both the workspace scope and the policy layer
 * (ARCHITECTURE.md §9), which is why no such accessor exists here.
 */
final class Attachment extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'attachable_id',
        'attachable_type',
        'uploaded_by',
        'disk',
        'path',
        'original_name',
        'mime',
        'extension',
        'size_bytes',
        'checksum',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /* ------------------------------------------------------------------ *
     * Derived attributes
     * ------------------------------------------------------------------ */

    /**
     * @return Attribute<string, never>
     */
    protected function humanSize(): Attribute
    {
        return Attribute::get(function (): string {
            $bytes = max(0, (int) $this->size_bytes);
            $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

            if ($bytes < 1024) {
                return $bytes.' '.$units[0];
            }

            $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
            $value = $bytes / (1024 ** $power);

            return number_format($value, $value >= 100 ? 0 : 1).' '.$units[$power];
        });
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isImage(): Attribute
    {
        return Attribute::get(fn (): bool => str_starts_with((string) $this->mime, 'image/'));
    }

    /**
     * The only supported way to fetch the bytes: a route that authorises the request and
     * streams from the private disk.
     *
     * @return Attribute<string, never>
     */
    protected function downloadUrl(): Attribute
    {
        return Attribute::get(fn (): string => route('attachments.download', $this));
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForAttachable(Builder $query, Model $attachable): Builder
    {
        return $query->where($this->qualifyColumn('attachable_type'), $attachable->getMorphClass())
            ->where($this->qualifyColumn('attachable_id'), $attachable->getKey());
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeImages(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('mime'), 'like', 'image/%');
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeUploadedBy(Builder $query, User|int $user): Builder
    {
        return $query->where(
            $this->qualifyColumn('uploaded_by'),
            $user instanceof User ? $user->getKey() : $user,
        );
    }
}
