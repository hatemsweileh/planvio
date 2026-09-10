<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Attachment;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;
use Illuminate\Database\Eloquent\Model;

/**
 * Files are streamed through an authorising controller (ARCHITECTURE.md §9), so this policy
 * is the only thing standing between a private disk and a URL.
 *
 * The matrix has no `attachment.view` cell: reading a file follows the record it hangs off,
 * which is `task.view` — `Y` for every workspace role, `*` for a guest. Deletion has its own
 * cell, with `own` for members and guests and `+` for a workspace manager.
 */
final class AttachmentPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, ?Model $attachable = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($attachable?->getAttribute('workspace_id')),
            Permission::TaskView,
        );
    }

    public function view(User $user, Attachment $attachment): bool
    {
        return $this->permits(
            $user,
            $attachment->workspace_id,
            Permission::TaskView,
            fn (): ?int => $this->parentProjectId($attachment),
        );
    }

    public function download(User $user, Attachment $attachment): bool
    {
        return $this->view($user, $attachment);
    }

    public function create(User $user, ?Model $attachable = null): bool
    {
        if ($attachable === null) {
            return $this->permitsSomewhere($user, $this->currentWorkspace(), Permission::AttachmentUpload);
        }

        $workspaceId = $attachable->getAttribute('workspace_id');

        return $this->permits(
            $user,
            $workspaceId,
            Permission::AttachmentUpload,
            fn (): ?int => $this->relatedProjectId($attachable, $workspaceId),
        );
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $this->permits(
            $user,
            $attachment->workspace_id,
            Permission::AttachmentDelete,
            fn (): ?int => $this->parentProjectId($attachment),
            fn (): bool => $this->isUploader($user, $attachment),
        );
    }

    public function restore(User $user, Attachment $attachment): bool
    {
        return $this->delete($user, $attachment);
    }

    public function forceDelete(User $user, Attachment $attachment): bool
    {
        return $this->delete($user, $attachment);
    }

    private function parentProjectId(Attachment $attachment): ?int
    {
        return $this->morphedProjectId(
            $attachment->relationLoaded('attachable') ? $attachment->attachable : null,
            $attachment->attachable_type,
            $attachment->attachable_id,
            $attachment->workspace_id,
        );
    }

    private function isUploader(User $user, Attachment $attachment): bool
    {
        return $attachment->uploaded_by !== null
            && (int) $attachment->uploaded_by === (int) $user->getKey();
    }
}
