<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\PlatformPage;
use App\Models\User;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

/**
 * The panel's landing page.
 *
 * Filament 5 does not register a dashboard unless one is declared, and without it `/admin`
 * redirects to whatever navigation item happens to be first — which would put an administrator
 * in the middle of the user list on every sign-in. This declares it, and restricts it the same
 * way {@see PlatformPage} restricts the rest.
 *
 * The widgets are discovered from `app/Filament/Widgets` and answer the four questions somebody
 * opens an admin panel with: how big is this installation, what has been done to it lately, is
 * anything failing, and what is the AI doing today.
 */
final class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return __('Overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('Overview');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isPlatformAdmin() && $user->is_active;
    }

    /**
     * @return int|array<string, ?int>
     */
    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
