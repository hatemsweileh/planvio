<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

/**
 * The writable surface of a workspace, as a typed carrier rather than a request array.
 *
 * Every field is nullable and null means *leave alone*, which is what lets one carrier serve
 * both create and update: CreateWorkspace fills the gaps from
 * {@see WorkspaceDefaults::columns()}, UpdateWorkspace simply skips them. Passing an empty
 * string is how a caller clears a nullable text column — that is a value, not an absence,
 * so it survives the filter and lands as null.
 */
final readonly class WorkspaceAttributes
{
    /**
     * Columns that accept null, and so can be cleared by passing an empty string.
     *
     * @var list<string>
     */
    private const CLEARABLE = ['description', 'logo_path'];

    /**
     * @param array<string, mixed>|null $settings
     */
    public function __construct(
        public ?string $name = null,
        public ?string $slug = null,
        public ?string $description = null,
        public ?string $logoPath = null,
        public ?string $accentColor = null,
        public ?string $timezone = null,
        public ?string $locale = null,
        public ?string $currency = null,
        public ?string $dateFormat = null,
        public ?int $weekStartsOn = null,
        public ?array $settings = null,
        public ?bool $isSuspended = null,
    ) {}

    /**
     * The provided fields only, keyed by column name.
     *
     * `settings` is excluded: it is a JSON document the actions merge rather than replace,
     * so it is applied deliberately instead of being filled in bulk.
     *
     * @return array<string, mixed>
     */
    public function toColumns(): array
    {
        $columns = [];

        foreach ($this->provided() as $column => $value) {
            if (is_string($value)) {
                $trimmed = trim($value);

                if ($trimmed === '' && ! in_array($column, self::CLEARABLE, true)) {
                    // A blank required column is an absence, not an instruction to blank it.
                    continue;
                }

                $columns[$column] = $trimmed === '' ? null : $trimmed;

                continue;
            }

            $columns[$column] = $value;
        }

        if (array_key_exists('currency', $columns) && is_string($columns['currency'])) {
            $columns['currency'] = mb_strtoupper(mb_substr($columns['currency'], 0, 3));
        }

        if (array_key_exists('week_starts_on', $columns)) {
            $columns['week_starts_on'] = max(0, min(6, (int) $columns['week_starts_on']));
        }

        return $columns;
    }

    public function hasSettings(): bool
    {
        return $this->settings !== null;
    }

    /**
     * Everything the caller actually passed, keyed by column name.
     *
     * @return array<string, string|int|bool>
     */
    private function provided(): array
    {
        $candidates = [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'logo_path' => $this->logoPath,
            'accent_color' => $this->accentColor,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'currency' => $this->currency,
            'date_format' => $this->dateFormat,
            'week_starts_on' => $this->weekStartsOn,
            'is_suspended' => $this->isSuspended,
        ];

        return array_filter($candidates, static fn (mixed $value): bool => $value !== null);
    }
}
