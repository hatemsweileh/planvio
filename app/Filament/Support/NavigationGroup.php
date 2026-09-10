<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * The sidebar's four sections.
 *
 * An enum rather than four string constants, and that is load-bearing: Filament orders sidebar
 * groups by the order the enum declares its cases, whereas string groups fall back to whatever
 * order the resources happened to be discovered in — alphabetical by directory, which would put
 * AI above accounts. Declaring the cases is the only way to control that ordering without
 * touching the panel provider.
 *
 * The grouping follows what an administrator is doing rather than what the tables are called:
 * *Platform* is who and what exists, *AI* is what the agent is allowed to do, *Logs* is what it
 * did, and *System* is the state of the installation itself.
 */
enum NavigationGroup: string implements HasIcon, HasLabel
{
    case Platform = 'platform';

    case Ai = 'ai';

    case Logs = 'logs';

    case System = 'system';

    public function getLabel(): string
    {
        return match ($this) {
            self::Platform => __('Platform'),
            self::Ai => __('AI'),
            self::Logs => __('Logs'),
            self::System => __('System'),
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Platform => Heroicon::OutlinedBuildingOffice,
            self::Ai => Heroicon::OutlinedCpuChip,
            self::Logs => Heroicon::OutlinedClipboardDocumentList,
            self::System => Heroicon::OutlinedServer,
        };
    }
}
