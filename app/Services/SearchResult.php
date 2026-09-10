<?php

declare(strict_types=1);

namespace App\Services;

/**
 * One hit from global search, in a shape a result list can render without knowing what it
 * is looking at.
 *
 * Nothing here is prose. `title`, `subtitle` and `excerpt` are values taken from the record;
 * the words around them ("in project X", "commented on") belong to the view, which has the
 * translation catalogue this service does not.
 */
final readonly class SearchResult
{
    /**
     * @param array<string, mixed> $meta type-specific facts a caller may need to route
     */
    public function __construct(
        public SearchType $type,
        public int $id,
        public string $title,
        public ?string $subtitle = null,
        public ?string $excerpt = null,
        public ?int $projectId = null,
        public ?string $projectName = null,
        public array $meta = [],
    ) {}

    /**
     * A plain-text snippet: tags stripped, entities decoded, whitespace collapsed, cut on a
     * word boundary. Comment bodies and wiki content are sanitised HTML, and a search result
     * that renders markup is both ugly and an injection surface waiting to be found.
     */
    public static function snippet(?string $html, int $length = 160): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > (int) ($length * 0.6)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut).'…';
    }

    /**
     * @return array{
     *     type: string,
     *     id: int,
     *     title: string,
     *     subtitle: string|null,
     *     excerpt: string|null,
     *     project_id: int|null,
     *     project_name: string|null,
     *     meta: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'excerpt' => $this->excerpt,
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'meta' => $this->meta,
        ];
    }
}
