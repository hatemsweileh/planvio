<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\HealthReport;
use App\Filament\Support\NavigationGroup;
use App\Filament\Support\PlatformPage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Nine checks, each with the evidence it is based on.
 *
 * The checks live in {@see HealthReport}; this page is the presentation and the refresh button.
 * They run on every load rather than being cached — a cached health report is a report about a
 * moment that has passed, and the reason somebody is on this page is that they just changed
 * something and want to know whether it worked.
 *
 * There is deliberately no navigation badge. Producing one would mean running the whole report,
 * including an outbound HTTP request with a five-second timeout, on every render of every screen
 * in the panel. A red dot is not worth making the rest of the panel slower than the thing it is
 * warning about.
 */
final class SystemHealth extends PlatformPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.system-health';

    public static function getNavigationLabel(): string
    {
        return __('System health');
    }

    public function getTitle(): string
    {
        return __('System health');
    }

    public function getSubheading(): ?string
    {
        return __('Each check states what it observed. Nothing here reports healthy on the strength of configuration alone.');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('Re-run checks'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                // The checks run in render, so re-rendering the component is the whole action.
                ->action(fn () => null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $report = new HealthReport;
        $checks = $report->all();

        return [
            'checks' => $checks,
            'overall' => $report->overall($checks),
        ];
    }
}
