<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Ai\Usage\UsageReporter;
use App\Filament\Support\NavigationGroup;
use App\Filament\Support\PlatformPage;
use App\Models\Workspace;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Token and request counts, from the `ai_usage_daily` rollup.
 *
 * # There is no cost column, and that is a decision rather than an omission
 *
 * Providers do not return a price with a completion. Prices change without notice, differ by
 * contract, by region, by cache-hit ratio and by whether a token was an input, an output or a
 * reasoning token — and a self-hosted installation may be pointed at a local endpoint where the
 * marginal cost is electricity.
 *
 * Any figure printed here with a currency symbol in front of it would therefore be a guess
 * wearing the clothes of a fact, and it would be believed: somebody would put it in a budget,
 * reconcile it against an invoice, and find it wrong. So this page reports what Planvio actually
 * knows — runs, tool calls, tokens, errors — and says plainly why there is no money on it. The
 * administrator knows what they pay per model; the per-model breakdown is here so they can apply
 * it themselves.
 *
 * {@see UsageReporter} carries the same reasoning at the query layer.
 */
final class AiUsage extends PlatformPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Ai;

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.ai-usage';

    /** The window, in days back from today, inclusive of today. */
    public int $days = 30;

    /**
     * Empty means every workspace, plus the platform-level buckets that belong to none.
     *
     * A string rather than an int because it is bound to a `<select>`, and Livewire would
     * otherwise have to guess what the empty option means for a nullable int. The cast happens
     * once, where the value is used.
     */
    public string $workspaceId = '';

    /**
     * @var array<int, int>
     */
    private const WINDOWS = [7, 30, 90, 365];

    public static function getNavigationLabel(): string
    {
        return __('Usage');
    }

    public function getTitle(): string
    {
        return __('AI usage');
    }

    public function getSubheading(): ?string
    {
        return __('Token and request counts from the daily rollup. No money: see the note below.');
    }

    public function updatedDays(): void
    {
        // A hand-edited value must not widen the query beyond what the rollup indexes support.
        if (! in_array($this->days, self::WINDOWS, true)) {
            $this->days = 30;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $this->updatedDays();

        $reporter = app(UsageReporter::class);
        $to = Carbon::today();
        $from = $to->copy()->subDays(max(0, $this->days - 1));
        $workspaceId = ctype_digit($this->workspaceId) ? (int) $this->workspaceId : null;

        return [
            'windows' => self::WINDOWS,
            'from' => $from,
            'to' => $to,
            'workspaces' => Workspace::query()->orderBy('name')->pluck('name', 'id')->all(),
            'summary' => $reporter->summary($from, $to, $workspaceId),
            'byDay' => $reporter->byDay($from, $to, $workspaceId),
            'byWorkspace' => $workspaceId === null ? $reporter->byWorkspace($from, $to, 10) : [],
            'byModel' => $reporter->byModel($from, $to, $workspaceId, 10),
            'byUser' => $reporter->byUser($from, $to, $workspaceId, 10),
            'byProvider' => $reporter->byProvider($from, $to, $workspaceId, 10),
        ];
    }
}
