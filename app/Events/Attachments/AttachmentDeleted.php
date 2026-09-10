<?php

declare(strict_types=1);

namespace App\Events\Attachments;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An attachment was removed.
 *
 * `bytesRemoved` says whether the file itself went with the row: a soft delete keeps the
 * bytes so the attachment can be restored, a purge does not.
 */
final class AttachmentDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Attachment $attachment,
        public readonly User $actor,
        public readonly bool $bytesRemoved,
    ) {}
}
