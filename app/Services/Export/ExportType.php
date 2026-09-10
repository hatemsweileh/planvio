<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Services\SearchType;

/**
 * The three things Planvio will hand back as a CSV.
 *
 * Local to the export service rather than an `App\Enums` case: nothing in the schema stores
 * it, it names a download somebody asked for and never a value that outlives the request
 * (the same reasoning as {@see SearchType}).
 */
enum ExportType: string
{
    case Tasks = 'tasks';
    case Projects = 'projects';
    case Time = 'time';

    public function label(): string
    {
        return match ($this) {
            self::Tasks => __('Tasks'),
            self::Projects => __('Projects'),
            self::Time => __('Time entries'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Tasks => 'icon.check-circle',
            self::Projects => 'icon.folder',
            self::Time => 'icon.clock',
        };
    }

    /**
     * What this export is, in a sentence, for the screen that offers it.
     */
    public function description(): string
    {
        return match ($this) {
            self::Tasks => __('One row per task, with its key, status, people, dates, estimate and tags.'),
            self::Projects => __('One row per project, with its owner, dates, progress and budget.'),
            self::Time => __('One row per logged entry, with the day, the person, the task and the minutes.'),
        };
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
