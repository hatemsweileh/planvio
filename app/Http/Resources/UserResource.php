<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * A person, as one workspace colleague sees another.
 *
 * The absentees are the point. `password`, `remember_token`, `two_factor_secret`,
 * `two_factor_recovery_codes` and `two_factor_confirmed_at` are never rendered here — not
 * hidden, not redacted, simply not named — and neither is `is_admin`, which says something
 * about the installation rather than about the workspace and is nobody's business from
 * inside a tenant.
 *
 * `avatar_url` is the uploaded image or null — never the generated fallback the product
 * draws, which would put several hundred bytes of identical SVG on every row of every list.
 *
 * `email` is included because it is already the identity a workspace works with: it is on
 * the member list in the product, it is on every webhook payload's actor, and an integration
 * that has to match Planvio users against its own directory has nothing else to match on.
 *
 * @property User $resource
 */
final class UserResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $avatar = $user->avatar_path;

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'job_title' => $user->job_title === null ? null : (string) $user->job_title,
            // The *uploaded* avatar, or null. `User::avatarUrl()` falls back to a generated
            // SVG data URI, which is right on a screen and wrong here: it is several hundred
            // bytes of identical markup repeated for every row of every list, and a client
            // that wants initials already has `name`.
            'avatar_url' => $avatar === null || $avatar === '' ? null : Storage::disk('public')->url($avatar),
            'timezone' => (string) $user->timezone,
            'locale' => (string) $user->locale,
            'is_active' => (bool) $user->is_active,
            'created_at' => self::iso($user->created_at),
        ];
    }
}
