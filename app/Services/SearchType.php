<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The kinds of record global search returns.
 *
 * Local to the service rather than an `App\Enums` case: it names result groups on a screen,
 * not a value that is ever persisted. Nothing in the schema stores it, so it does not belong
 * in the enum set §6 fixes.
 *
 * Case order is the order the groups are rendered in — coarse to fine, so a search that
 * matches everything leads with the place someone was probably heading.
 */
enum SearchType: string
{
    case Project = 'project';
    case Task = 'task';
    case Milestone = 'milestone';
    case WikiPage = 'wiki_page';
    case Comment = 'comment';
    case User = 'user';
    case Team = 'team';

    public function label(): string
    {
        return __('search.types.'.$this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::Project => 'heroicon-o-rectangle-stack',
            self::Task => 'heroicon-o-check-circle',
            self::Milestone => 'heroicon-o-flag',
            self::WikiPage => 'heroicon-o-document-text',
            self::Comment => 'heroicon-o-chat-bubble-left-right',
            self::User => 'heroicon-o-user',
            self::Team => 'heroicon-o-user-group',
        };
    }

    /**
     * @param iterable<int, SearchType|string>|null $types
     * @return list<SearchType> every type when nothing usable was asked for
     */
    public static function normalise(?iterable $types): array
    {
        if ($types === null) {
            return self::cases();
        }

        $selected = [];

        foreach ($types as $type) {
            $case = $type instanceof self ? $type : self::tryFrom((string) $type);

            if ($case !== null) {
                $selected[$case->value] = $case;
            }
        }

        if ($selected === []) {
            return self::cases();
        }

        // Preserve declaration order rather than the caller's, so groups always render the
        // same way round.
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => isset($selected[$case->value]),
        ));
    }
}
