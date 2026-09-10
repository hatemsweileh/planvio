<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\NavigationGroup;
use App\Filament\Support\PlatformPage;
use App\Filament\Support\SystemFacts;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * What this installation actually is: versions, limits, paths, and the cron lines to copy.
 *
 * # The cron section is the reason this page exists
 *
 * docs/CPANEL.md sends people here by name. Planvio is installed by uploading a ZIP to shared
 * hosting, and the single most common way for an otherwise correct installation to be silently
 * broken is a cron entry pointing at the wrong PHP binary: cPanel's `/usr/local/bin/php` is
 * frequently an older version than the one serving the site, and the resulting failure produces
 * no error anybody can see — just reminders that never arrive and recurring tasks that never
 * appear.
 *
 * So the commands rendered here are not examples. `PHP_BINARY` is the interpreter running this
 * request, and `base_path('artisan')` is where artisan really is, which makes the two lines
 * correct by construction for this account, on this host, today. They are meant to be copied
 * verbatim into the host's cron panel.
 */
final class SystemInformation extends PlatformPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInformationCircle;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.system-information';

    public static function getNavigationLabel(): string
    {
        return __('System information');
    }

    public function getTitle(): string
    {
        return __('System information');
    }

    public function getSubheading(): ?string
    {
        return __('Everything a support request needs, and the exact cron commands for this installation.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'application' => [
                __('Planvio version') => SystemFacts::appVersion(),
                __('Laravel') => SystemFacts::laravelVersion(),
                __('PHP') => SystemFacts::phpVersion(),
                __('Environment') => (string) config('app.env'),
                __('Debug mode') => config('app.debug') ? __('On — turn this off in production') : __('Off'),
                __('URL') => (string) config('app.url'),
                __('Timezone') => (string) config('app.timezone'),
                __('Locale') => (string) config('app.locale'),
            ],
            'schema' => SystemFacts::databaseVersion(),
            'database' => SystemFacts::database(),
            'server' => [
                __('Server software') => SystemFacts::serverSoftware(),
                __('Operating system') => SystemFacts::operatingSystem(),
                __('PHP binary') => SystemFacts::phpBinary(),
                __('Memory limit') => SystemFacts::memoryLimit(),
                __('Max execution time') => SystemFacts::maxExecutionTime(),
            ],
            'uploads' => SystemFacts::uploadLimits(),
            'storage' => SystemFacts::storage(),
            'queue' => SystemFacts::queue(),
            'scheduler' => SystemFacts::schedulerLastRun(),
            'ai' => SystemFacts::ai(),
            'cron' => SystemFacts::cronLines(),
        ];
    }
}
