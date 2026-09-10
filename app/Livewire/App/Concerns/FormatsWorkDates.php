<?php

declare(strict_types=1);

namespace App\Livewire\App\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Livewire\Attributes\Computed;
use Throwable;

/**
 * Due-date language, resolved in the reader's day.
 *
 * "Overdue", "today" and "this week" are claims about the person looking at the screen, not
 * about the server, so every one of them is measured against a day computed in the viewer's
 * timezone — theirs when they have set one, the workspace's otherwise.
 *
 * The tone helper returns a *key*, never a class string. Tailwind scans source text and
 * never evaluates it, so a class assembled from a variable compiles to nothing; the view
 * looks the key up in an array of complete class names it wrote out in full.
 *
 * Requires the using component to expose a `$workspace` property.
 */
trait FormatsWorkDates
{
    #[Computed]
    public function timezone(): string
    {
        $candidates = [
            auth()->user()?->timezone,
            $this->workspace->timezone ?? null,
            'UTC',
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            try {
                CarbonImmutable::now($candidate);

                return $candidate;
            } catch (Throwable) {
                continue;
            }
        }

        return 'UTC';
    }

    #[Computed]
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone)->startOfDay();
    }

    /**
     * A short, human due date: how late it is, or how soon.
     */
    public function dueLabel(?DateTimeInterface $date): string
    {
        if (! $date instanceof DateTimeInterface) {
            return '';
        }

        $today = $this->today;
        $due = CarbonImmutable::instance($date)->setTimezone($today->getTimezone())->startOfDay();
        $days = (int) round((float) $due->diffInDays($today, false));

        return match (true) {
            $days === 0 => __('Today'),
            $days === -1 => __('Tomorrow'),
            $days === 1 => __('Yesterday'),
            $days > 1 => trans_choice('{1}:count day late|[2,*]:count days late', $days, ['count' => $days]),
            $days >= -6 => $due->translatedFormat('D'),
            $due->year === $today->year => $due->translatedFormat('j M'),
            default => $due->translatedFormat('j M Y'),
        };
    }

    /**
     * One of `late`, `today`, `soon`, `later` or `none`. The view maps it to colour.
     */
    public function dueTone(?DateTimeInterface $date): string
    {
        if (! $date instanceof DateTimeInterface) {
            return 'none';
        }

        $today = $this->today;
        $due = CarbonImmutable::instance($date)->setTimezone($today->getTimezone())->startOfDay();

        return match (true) {
            $due->lessThan($today) => 'late',
            $due->equalTo($today) => 'today',
            $due->lessThan($today->addDays(3)) => 'soon',
            default => 'later',
        };
    }

    /**
     * Minutes as "6h 30m" — the shape an estimate is read in, never a bare number.
     */
    public function durationLabel(int $minutes): string
    {
        if ($minutes <= 0) {
            return '—';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return __(':count m', ['count' => $rest]);
        }

        return $rest === 0
            ? __(':count h', ['count' => $hours])
            : __(':hours h :minutes m', ['hours' => $hours, 'minutes' => $rest]);
    }
}
