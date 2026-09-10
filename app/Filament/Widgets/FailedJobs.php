<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Support\AdminAudit;
use App\Filament\Support\PlatformWidget;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queued work that gave up.
 *
 * # Why this is on the dashboard at all
 *
 * A failed job on Planvio's hosting is invisible by design: there is no supervisor, no
 * monitoring agent, and frequently no readable log. The failure of a queued email or a webhook
 * delivery produces no error anybody sees — it simply does not happen. This widget is the only
 * place an administrator would ever find out.
 *
 * # Why the buttons exist rather than a documented command
 *
 * `php artisan queue:retry all` is the usual answer and it is useless to most of the people
 * running this: cPanel and CloudLinux accounts routinely have no shell at all. Both controls
 * therefore run the same Artisan commands from here, and both say plainly what they do —
 * retrying re-executes work that has already been attempted, which for a webhook means the
 * receiver may see the delivery twice.
 *
 * Reads are defensive: a `failed_jobs` table that is not there, or a failer configured to
 * discard, must not break the dashboard.
 */
final class FailedJobs extends Widget
{
    use PlatformWidget;

    protected string $view = 'filament.widgets.failed-jobs';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

    /** Enough to see the pattern; the count says how much more there is. */
    private const PREVIEW = 5;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'supported' => $this->usesDatabaseFailer(),
            'count' => $this->count(),
            'jobs' => $this->recent(),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Controls
     * ------------------------------------------------------------------ */

    public function retryAll(): void
    {
        $before = $this->count() ?? 0;

        try {
            Artisan::call('queue:retry', ['id' => ['all']]);
        } catch (Throwable $exception) {
            $this->reportFailure($exception);

            return;
        }

        AdminAudit::record(
            'admin.failed_jobs_retried',
            __('Failed queue jobs pushed back onto the queue from the administration panel.'),
            properties: ['count' => $before],
        );

        Notification::make()
            ->title(__(':count jobs are back on the queue.', ['count' => $before]))
            ->body(__('They run on the next queue worker tick, within five minutes if the cron entry is in place.'))
            ->success()
            ->send();
    }

    public function deleteAll(): void
    {
        $before = $this->count() ?? 0;

        try {
            Artisan::call('queue:flush');
        } catch (Throwable $exception) {
            $this->reportFailure($exception);

            return;
        }

        AdminAudit::record(
            'admin.failed_jobs_flushed',
            __('Failed queue jobs discarded from the administration panel.'),
            properties: ['count' => $before],
        );

        Notification::make()
            ->title(__(':count failed jobs discarded.', ['count' => $before]))
            ->body(__('The work they represented is gone. Nothing will retry it.'))
            ->warning()
            ->send();
    }

    /* ------------------------------------------------------------------ *
     * Reads
     * ------------------------------------------------------------------ */

    public function count(): ?int
    {
        if (! $this->usesDatabaseFailer()) {
            return null;
        }

        try {
            return DB::table($this->table())->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{id: string, queue: string, job: string, failed_at: ?Carbon, reason: string}>
     */
    private function recent(): array
    {
        if (! $this->usesDatabaseFailer()) {
            return [];
        }

        try {
            $rows = DB::table($this->table())
                ->orderByDesc('failed_at')
                ->limit(self::PREVIEW)
                ->get(['uuid', 'id', 'queue', 'payload', 'exception', 'failed_at']);
        } catch (Throwable) {
            return [];
        }

        $jobs = [];

        foreach ($rows as $row) {
            $jobs[] = [
                'id' => (string) ($row->uuid ?? $row->id ?? ''),
                'queue' => (string) ($row->queue ?? 'default'),
                'job' => $this->jobName(is_string($row->payload ?? null) ? $row->payload : null),
                'failed_at' => $this->moment($row->failed_at ?? null),
                'reason' => $this->firstLine(is_string($row->exception ?? null) ? $row->exception : null),
            ];
        }

        return $jobs;
    }

    /**
     * The class the job wraps, dug out of the serialised payload.
     *
     * The payload's `displayName` is what `queue:failed` prints, and it is what somebody
     * recognises. `commandName` is the fallback for a job queued by an older release.
     */
    private function jobName(?string $payload): string
    {
        if ($payload === null) {
            return __('Unknown job');
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return __('Unknown job');
        }

        foreach (['displayName', 'commandName'] as $key) {
            $value = $decoded[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return class_basename($value);
            }
        }

        return __('Unknown job');
    }

    /**
     * The first line of the stack trace, which is the exception class and message and the only
     * part worth putting on a dashboard.
     */
    private function firstLine(?string $exception): string
    {
        if ($exception === null || trim($exception) === '') {
            return __('No exception recorded');
        }

        $line = trim((string) strtok($exception, "\n"));

        return Str::limit($line === '' ? $exception : $line, 140);
    }

    private function moment(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse(is_string($value) ? $value : (string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function usesDatabaseFailer(): bool
    {
        return in_array((string) config('queue.failed.driver', ''), ['database', 'database-uuids'], true);
    }

    private function table(): string
    {
        $table = config('queue.failed.table');

        return is_string($table) && $table !== '' ? $table : 'failed_jobs';
    }

    private function reportFailure(Throwable $exception): void
    {
        Notification::make()
            ->title(__('That could not be completed.'))
            // The message is ours, not the exception's: a queue exception can carry the
            // serialised job with it, and a job payload can carry anything.
            ->body(__('The queue could not be reached. Check that the failed jobs table exists and the database is available.'))
            ->danger()
            ->send();

        report($exception);
    }
}
