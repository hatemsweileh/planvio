<?php

declare(strict_types=1);

namespace App\Events\Attachments;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * A file passed the upload gate and is now on the private disk.
 */
final class AttachmentStored implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Attachment $attachment,
        public readonly Model $attachable,
        public readonly ?User $actor,
    ) {}
}
