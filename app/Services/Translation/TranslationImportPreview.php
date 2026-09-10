<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * What importing a file would do, worked out before anything is written.
 *
 * An import of a translated catalogue is the one operation in the translation screens that
 * cannot be undone by looking at it: three thousand rows change at once, and "it replaced
 * work somebody did last week" is discovered days later. So the analysis and the write are
 * two steps, and this is the first one — the same object drives the summary an administrator
 * reads and, on approval, the write itself, so what was shown is exactly what happens.
 *
 * The counts are per catalogue because that is the unit the file is organised in and the unit
 * a translator works in. `unknown` and `rejected` are the two that matter most: the first says
 * the file was made against a different version of the product, the second says lines in it
 * would break at render time and are being left out.
 */
final readonly class TranslationImportPreview
{
    /** Rejections kept for display. Past this the pattern is clear without the rest. */
    public const MAX_REJECTIONS = 50;

    /**
     * @param array<string, array{new: int, changed: int, unchanged: int, cleared: int, unknown: int, rejected: int}> $catalogues
     * @param list<array{catalogue: string, key: string, missing: list<string>}> $rejections
     * @param array<string, array<string, string|null>> $writes catalogue => key => value, null clearing one
     */
    public function __construct(
        public array $catalogues,
        public array $rejections,
        public array $writes,
        public bool $rejectionsTruncated = false,
    ) {}

    /**
     * @param 'new'|'changed'|'unchanged'|'cleared'|'unknown'|'rejected' $measure
     */
    public function total(string $measure): int
    {
        $total = 0;

        foreach ($this->catalogues as $counts) {
            $total += $counts[$measure];
        }

        return $total;
    }

    /**
     * Lines that would actually be written: added, replaced, or emptied back to the shipped
     * English. An unchanged line is not a write, and neither is one that was refused.
     */
    public function writeCount(): int
    {
        $count = 0;

        foreach ($this->writes as $values) {
            $count += count($values);
        }

        return $count;
    }

    public function isEmpty(): bool
    {
        return $this->catalogues === [];
    }

    /**
     * Plain arrays, for a Livewire property and a Blade table.
     *
     * The writes are deliberately not part of this: they are the whole file, and a component
     * that carried them in its state would push a megabyte of JSON through every subsequent
     * request on the page. The preview is recomputed from the staged file when it is applied.
     *
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'catalogues' => $this->catalogues,
            'rejections' => $this->rejections,
            'rejections_truncated' => $this->rejectionsTruncated,
            'writes' => $this->writeCount(),
            'totals' => [
                'new' => $this->total('new'),
                'changed' => $this->total('changed'),
                'unchanged' => $this->total('unchanged'),
                'cleared' => $this->total('cleared'),
                'unknown' => $this->total('unknown'),
                'rejected' => $this->total('rejected'),
            ],
        ];
    }
}
