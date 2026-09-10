<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * The base for the panel's custom pages.
 *
 * Filament's own default is that any authenticated panel user may open a custom page. Panel
 * entry is already restricted to platform super-admins by `User::canAccessPanel()`, so that
 * default is not wrong here — but these pages read system paths, queue depth and AI
 * configuration, and "it was already checked upstream" is a poor thing for that to depend on.
 * The check is repeated, on every mount and every Livewire hydration, so an account demoted
 * mid-session loses the page rather than keeping it until it navigates.
 */
abstract class PlatformPage extends Page
{
    public static function canAccess(): bool
    {
        return self::administrator() !== null;
    }

    public static function administrator(): ?User
    {
        $user = Auth::user();

        return $user instanceof User && $user->isPlatformAdmin() && $user->is_active
            ? $user
            : null;
    }
}
