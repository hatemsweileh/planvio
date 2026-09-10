<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The visibility rule shared by the dashboard widgets.
 *
 * A trait rather than a base class, because the widgets extend three different Filament bases
 * (stats, table, plain) and only agree on this one thing: a widget on this dashboard reads
 * across every workspace on the installation, so it renders for an active platform super-admin
 * and for nobody else.
 */
trait PlatformWidget
{
    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isPlatformAdmin() && $user->is_active;
    }
}
