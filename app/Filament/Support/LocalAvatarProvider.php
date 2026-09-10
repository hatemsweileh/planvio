<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The avatar in the panel's user menu, drawn from this installation and nowhere else.
 *
 * Filament's shipped default builds a URL against `ui-avatars.com`, which means every load
 * of every administration screen sends the signed-in administrator's initials to a third
 * party and asks it to draw them. On a self-hosted product that is wrong twice over: it is
 * an outbound request nobody consented to, and on an installation without internet access —
 * which Planvio explicitly supports — it is a broken image.
 *
 * It is also the one thing on `/admin` that the panel's Content-Security-Policy would
 * block, and a policy whose first effect is a broken avatar teaches administrators to turn
 * the policy off.
 *
 * The product already answers this question: `User::avatarUrl()` returns the uploaded photo
 * when there is one and a data-URI SVG of the person's initials when there is not, so the
 * panel now shows exactly what the product shows.
 */
final class LocalAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        if ($record instanceof User) {
            return $record->avatarUrl();
        }

        // The panel has no tenancy and no other avatar-bearing record, so nothing reaches
        // this today. If something ever does, a plain disc in this origin is the right
        // answer — the point of this class is that the panel asks no one else for a picture.
        return 'data:image/svg+xml;base64,'.base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">'
            .'<rect width="64" height="64" rx="32" fill="#6B7A99"/></svg>',
        );
    }
}
