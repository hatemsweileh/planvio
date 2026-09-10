<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AiRunStatus;
use App\Enums\ToolRunStatus;
use App\Filament\Support\PlatformWidget;
use App\Models\AiRun;
use App\Models\AiToolRun;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * What the agent has done since midnight.
 *
 * Today rather than a rolling window, because the question this answers is "is something running
 * away right now" — a loop, a misconfigured automation, a run that keeps failing — and a
 * seven-day average is exactly the shape that hides it.
 *
 * The widget hides itself entirely when AI is switched off. An installation that has deliberately
 * never turned AI on should not carry four permanent zeroes on its dashboard.
 */
final class AiRunsToday extends StatsOverviewWidget
{
    use PlatformWidget {
        canView as canViewAsAdministrator;
    }

    protected ?string $heading = null;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return self::canViewAsAdministrator() && (bool) config('ai.enabled', false);
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $since = Carbon::today();

        $runs = AiRun::withoutWorkspaceScope()->where('created_at', '>=', $since);

        $total = (clone $runs)->count();
        $failed = (clone $runs)->where('status', AiRunStatus::Failed->value)->count();
        $awaiting = AiRun::withoutWorkspaceScope()
            ->where('status', AiRunStatus::AwaitingApproval->value)
            ->count();

        $tokens = (clone $runs)->sum('tokens_in') + (clone $runs)->sum('tokens_out');

        $toolCalls = AiToolRun::withoutWorkspaceScope()
            ->where('created_at', '>=', $since)
            ->count();

        $pendingApproval = AiToolRun::withoutWorkspaceScope()
            ->where('status', ToolRunStatus::PendingApproval->value)
            ->count();

        return [
            Stat::make(__('AI runs today'), number_format($total))
                ->description($failed > 0
                    ? __(':count failed', ['count' => $failed])
                    : __('None failed'))
                ->color($failed > 0 ? 'danger' : 'gray'),

            Stat::make(__('Tool calls today'), number_format($toolCalls))
                ->description($pendingApproval > 0
                    ? __(':count awaiting approval', ['count' => $pendingApproval])
                    : __('Nothing awaiting approval'))
                ->color($pendingApproval > 0 ? 'warning' : 'gray'),

            Stat::make(__('Tokens today'), number_format((int) $tokens))
                ->description(__('In and out combined. Planvio does not price tokens.'))
                ->color('gray'),

            Stat::make(__('Runs awaiting approval'), number_format($awaiting))
                ->description($awaiting > 0
                    ? __('Somebody has to say yes before these continue')
                    : __('Nothing is waiting on a human'))
                ->color($awaiting > 0 ? 'warning' : 'gray'),
        ];
    }
}
