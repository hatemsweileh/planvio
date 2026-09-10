<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Support\PlatformWidget;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * How big this installation is.
 *
 * The secondary line under each number is the part worth having: a total of forty workspaces
 * means nothing on its own, and "thirty-eight active, two suspended" is a description of the
 * installation. Every count deliberately escapes the tenant scope — this is the one place in
 * Planvio that is supposed to see across every workspace at once.
 */
final class PlatformTotals extends StatsOverviewWidget
{
    use PlatformWidget;

    protected ?string $heading = null;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $workspaces = Workspace::query()->count();
        $suspended = Workspace::query()->where('is_suspended', true)->count();

        $users = User::query()->count();
        $inactive = User::query()->where('is_active', false)->count();
        $admins = User::query()->where('is_admin', true)->where('is_active', true)->count();

        $projects = Project::withoutWorkspaceScope()->count();
        $archived = Project::withoutWorkspaceScope()->where('is_archived', true)->count();

        $tasks = Task::withoutWorkspaceScope()->count();
        $openTasks = Task::withoutWorkspaceScope()->whereNull('completed_at')->count();

        return [
            Stat::make(__('Workspaces'), number_format($workspaces))
                ->description($suspended > 0
                    ? __(':count suspended', ['count' => $suspended])
                    : __('None suspended'))
                ->color($suspended > 0 ? 'warning' : 'gray'),

            Stat::make(__('People'), number_format($users))
                ->description(__(':admins platform admins · :inactive deactivated', [
                    'admins' => $admins,
                    'inactive' => $inactive,
                ]))
                ->color('gray'),

            Stat::make(__('Projects'), number_format($projects))
                ->description(__(':count archived', ['count' => $archived]))
                ->color('gray'),

            Stat::make(__('Tasks'), number_format($tasks))
                ->description(__(':count still open', ['count' => number_format($openTasks)]))
                ->color('gray'),
        ];
    }
}
