<?php

declare(strict_types=1);

namespace App\Actions\Wiki;

use App\Enums\WikiVisibility;
use App\Events\Wiki\WikiPageUpdated;
use App\Exceptions\InvalidWikiPage;
use App\Models\User;
use App\Models\WikiPage;
use App\Services\ActivityLogger;
use App\Services\HtmlSanitizer;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Edit a wiki page.
 *
 * The body is re-sanitised on every save: a page that was safe when it was written must not
 * become a payload when it is edited, and the sanitiser is the only thing standing between
 * a paste from an untrusted source and a stored script.
 *
 * The slug does not follow the title unless the caller asks. A page's URL gets shared,
 * bookmarked and linked from other pages, so fixing a typo in a heading must not quietly
 * break every reference to it; `reslug: true` is the caller saying the URL should change.
 */
final class UpdateWikiPage
{
    private const EXCERPT_CHARS = 200;

    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(
        WikiPage $page,
        User $editor,
        string $title,
        ?string $content = null,
        ?WikiVisibility $visibility = null,
        ?string $excerpt = null,
        bool $reslug = false,
    ): WikiPage {
        $title = trim($title);

        if ($title === '') {
            throw InvalidWikiPage::titleRequired();
        }

        $body = $content === null ? null : $this->sanitizer->sanitize($content);

        $page->title = mb_substr($title, 0, 255);
        $page->content = $body;
        $page->excerpt = $this->excerpt($excerpt, $body);
        $page->last_edited_by = $editor->getKey();

        if ($visibility instanceof WikiVisibility) {
            $page->visibility = $visibility;
        }

        if ($reslug) {
            $page->slug = WikiSlug::unique(
                $title,
                (int) $page->workspace_id,
                $page->project_id === null ? null : (int) $page->project_id,
                (int) $page->getKey(),
            );
        }

        $changes = ActivityLogger::changes($page);

        // `last_edited_by` alone is not an edit: re-saving identical content must not bump
        // the page in the recently-changed list.
        unset($changes['last_edited_by']);

        if ($changes === []) {
            return $page;
        }

        DB::transaction(function () use ($page, $editor, $changes): void {
            $page->save();

            $this->activity->forUser($editor)->log($page, 'updated', $changes);
        });

        $this->events->dispatch(new WikiPageUpdated($page, $editor, $changes));

        return $page->refresh();
    }

    private function excerpt(?string $given, ?string $body): ?string
    {
        if ($given !== null && trim($given) !== '') {
            return mb_substr(trim($given), 0, 255);
        }

        if ($body === null || $body === '') {
            return null;
        }

        $derived = $this->sanitizer->sanitizeExcerpt($body, self::EXCERPT_CHARS);

        return $derived === '' ? null : $derived;
    }
}
