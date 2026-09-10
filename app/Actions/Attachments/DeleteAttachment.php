<?php

declare(strict_types=1);

namespace App\Actions\Attachments;

use App\Events\Attachments\AttachmentDeleted;
use App\Models\Attachment;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;

/**
 * Remove an attachment.
 *
 * Two modes, and the difference matters. The default soft-deletes the row and leaves the
 * bytes where they are, so a mistaken deletion is undoable. `purge: true` deletes the file
 * and then hard-deletes the row — used when somebody uploaded something that must actually
 * be gone, and by retention cleanup.
 *
 * The bytes are only removed once nothing else points at them. Storage names are random so
 * two rows should never share a path, but "should never" is not a reason to delete a file
 * another record still lists.
 */
final class DeleteAttachment
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
        private readonly FilesystemFactory $filesystem,
    ) {}

    public function __invoke(Attachment $attachment, User $actor, bool $purge = false): Attachment
    {
        if ($attachment->trashed() && ! $purge) {
            return $attachment;
        }

        $attachable = $attachment->attachable;
        $bytesRemoved = false;

        if ($purge && $this->isLastReference($attachment)) {
            $bytesRemoved = $this->filesystem
                ->disk((string) $attachment->disk)
                ->delete((string) $attachment->path);
        }

        DB::transaction(function () use ($attachment, $actor, $attachable, $purge, $bytesRemoved): void {
            if ($purge) {
                $attachment->forceDelete();
            } else {
                $attachment->delete();
            }

            $this->activity->forUser($actor)->log($attachable ?? $attachment, 'attachment_removed', [
                'attachment_id' => (int) $attachment->getKey(),
                'original_name' => $attachment->original_name,
                'size_bytes' => (int) $attachment->size_bytes,
                'purged' => $purge,
                'bytes_removed' => $bytesRemoved,
            ]);
        });

        $this->events->dispatch(new AttachmentDeleted($attachment, $actor, $bytesRemoved));

        return $attachment;
    }

    private function isLastReference(Attachment $attachment): bool
    {
        return Attachment::withoutWorkspaceScope()
            ->withTrashed()
            ->where('disk', $attachment->disk)
            ->where('path', $attachment->path)
            ->whereKeyNot($attachment->getKey())
            ->doesntExist();
    }
}
