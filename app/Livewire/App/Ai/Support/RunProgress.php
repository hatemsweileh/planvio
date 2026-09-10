<?php

declare(strict_types=1);

namespace App\Livewire\App\Ai\Support;

use App\Enums\AiRunStatus;
use App\Enums\ToolRunStatus;
use App\Models\AiRun;
use App\Models\AiToolRun;
use Illuminate\Support\Carbon;

/**
 * What the assistant is doing right now, in words, and how hard to keep asking.
 *
 * Planvio has no persistent worker: a chat request is recorded as an `ai_runs` row and
 * drained by a cron-started queue worker (docs/QUEUE.md), so the surface polls. A bare
 * spinner would be dishonest about that — "queued" and "running the third of five tool
 * calls" are different situations and a person waiting deserves to be told which one they
 * are in. Every line below is derived from the run's own columns, never guessed.
 *
 * The interval backs off with the run's age. The first seconds are the ones a person is
 * actually watching, and a run that has been going three minutes is one they have looked
 * away from; polling it every two seconds would spend the shared-hosting request budget on
 * nobody's attention.
 */
final readonly class RunProgress
{
    /** After this long in `queued`, the wait is worth explaining rather than just showing. */
    private const SLOW_QUEUE_SECONDS = 45;

    public function __construct(
        public string $headline,
        public ?string $detail,
        public string $tone,
        public bool $live,
        public string $interval,
    ) {}

    /**
     * @param AiToolRun|null $lastCall the most recently recorded call of this run
     */
    public static function for(AiRun $run, ?AiToolRun $lastCall = null): self
    {
        $status = $run->status ?? AiRunStatus::Queued;
        $age = self::ageSeconds($run);

        return match ($status) {
            AiRunStatus::Queued => new self(
                headline: __('Queued'),
                detail: $age >= self::SLOW_QUEUE_SECONDS
                    ? __('The queue worker has not picked this up yet. It runs on a schedule, so a short wait is normal.')
                    : __('Waiting for the queue worker to pick it up.'),
                tone: 'gray',
                live: true,
                interval: self::interval($age),
            ),
            AiRunStatus::Running => self::running($run, $lastCall, $age),
            AiRunStatus::AwaitingApproval => new self(
                headline: __('Waiting for a decision'),
                detail: $lastCall instanceof AiToolRun
                    ? __(':tool needs a human approval before it runs. Nothing has changed yet.', [
                        'tool' => ToolTrace::humanise((string) $lastCall->tool),
                    ])
                    : __('An action needs a human approval before it runs. Nothing has changed yet.'),
                tone: 'amber',
                live: true,
                interval: self::interval($age),
            ),
            default => new self(
                headline: $status->label(),
                detail: null,
                tone: $status->color(),
                live: false,
                interval: self::interval($age),
            ),
        };
    }

    /**
     * The one branch with something to say beyond the status name: which step of the loop.
     */
    private static function running(AiRun $run, ?AiToolRun $lastCall, int $age): self
    {
        $steps = (int) $run->steps;

        $detail = match (true) {
            $lastCall instanceof AiToolRun && $lastCall->status === ToolRunStatus::PendingApproval => __(
                'Running :tool — waiting on an approval.',
                ['tool' => ToolTrace::humanise((string) $lastCall->tool)],
            ),
            $lastCall instanceof AiToolRun && ($lastCall->status?->isTerminal() ?? false) => __(
                'Ran :tool. Deciding what to do next.',
                ['tool' => ToolTrace::humanise((string) $lastCall->tool)],
            ),
            $lastCall instanceof AiToolRun => __('Running :tool.', [
                'tool' => ToolTrace::humanise((string) $lastCall->tool),
            ]),
            $steps <= 0 => __('Sending your request to the model.'),
            default => __('Reading what it needs from this workspace.'),
        };

        return new self(
            headline: trans_choice(
                '{0}Working|{1}Working · step :count|[2,*]Working · step :count',
                $steps,
                ['count' => $steps],
            ),
            detail: $detail,
            tone: 'blue',
            live: true,
            interval: self::interval($age),
        );
    }

    /**
     * The poll interval as Livewire spells it, widening with the run's age.
     */
    public static function interval(int $ageSeconds): string
    {
        return match (true) {
            $ageSeconds <= 20 => '2s',
            $ageSeconds <= 60 => '4s',
            $ageSeconds <= 180 => '8s',
            default => '20s',
        };
    }

    private static function ageSeconds(AiRun $run): int
    {
        $started = $run->created_at;

        if (! $started instanceof Carbon) {
            return 0;
        }

        return max(0, (int) round(abs($started->diffInSeconds(Carbon::now()))));
    }
}
