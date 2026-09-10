<?php

declare(strict_types=1);

namespace App\Services;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Str;

/**
 * The single gate every piece of user-authored rich text passes through before it is
 * persisted (ARCHITECTURE.md §5.4, §5.6 — `comments.body` and `wiki_pages.content` are
 * documented as *sanitised* HTML).
 *
 * Sanitisation happens on write, not on render: a stored payload that never contained
 * script cannot be re-introduced by a template that forgets to escape, by an export, by
 * the API, or by the AI layer quoting stored content back to a model. Everything outside
 * the allow-list is removed rather than escaped, so the surviving markup is exactly the
 * conservative subset below and nothing else.
 *
 * The allow-list is deliberately small. `style` is absent (CSS is an XSS vector through
 * `expression()`, `url(javascript:)` and overlay attacks), every `on*` handler is absent,
 * and `a`/`img` accept only schemes that cannot execute.
 */
final class HtmlSanitizer
{
    /**
     * Elements, with their permitted attributes, that survive sanitisation.
     */
    private const ALLOWED = 'p,br,strong,em,u,s,a[href|title],ul,ol,li,blockquote,code,pre,'
        .'h1,h2,h3,img[src|alt|title|width|height],hr,table,thead,tbody,tr,'
        .'th[colspan|rowspan],td[colspan|rowspan],span,div';

    /**
     * Schemes an `href` or `src` may use. Everything else — `javascript:`, `data:`,
     * `vbscript:`, `file:` — is dropped with the attribute.
     *
     * @var array<string, bool>
     */
    private const ALLOWED_SCHEMES = [
        'http' => true,
        'https' => true,
        'mailto' => true,
    ];

    /**
     * Every link leaves the application, so every link is treated as hostile: `noopener`
     * and `noreferrer` deny the opened page a handle on ours, `nofollow` denies it our
     * ranking. Forced onto all links rather than only outbound ones, because "is this
     * host ours" is a question the sanitiser cannot answer reliably behind a proxy.
     */
    private const LINK_REL = 'noopener noreferrer nofollow';

    private ?HTMLPurifier $purifier = null;

    /**
     * Reduce arbitrary HTML to the allow-list above.
     *
     * Returns an empty string for input that carries no content, so callers can treat
     * "nothing survived sanitisation" as "empty" without a second check.
     */
    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $clean = $this->purifier()->purify($html);

        return trim($this->forceLinkRelations($clean));
    }

    /**
     * A plain-text summary of sanitised HTML, for `wiki_pages.excerpt`, search results,
     * notification bodies and list previews.
     *
     * Text, not markup: an excerpt is rendered in places that have no room to re-sanitise
     * and no business rendering tags at all.
     */
    public function sanitizeExcerpt(string $html, int $chars = 160): string
    {
        if ($chars <= 0) {
            return '';
        }

        $clean = $this->sanitize($html);

        if ($clean === '') {
            return '';
        }

        // Block boundaries carry meaning that strip_tags would otherwise swallow:
        // "<p>One</p><p>Two</p>" must not become "OneTwo".
        $spaced = preg_replace('#<(?:br|/p|/div|/li|/h[1-3]|/tr|/blockquote|/pre|/td|/th|/table)\b[^>]*>#i', ' ', $clean);

        $text = html_entity_decode(strip_tags($spaced ?? $clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return Str::limit($text, $chars, '…', preserveWords: true);
    }

    private function purifier(): HTMLPurifier
    {
        return $this->purifier ??= new HTMLPurifier($this->configuration());
    }

    private function configuration(): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();

        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', self::ALLOWED);
        $config->set('URI.AllowedSchemes', self::ALLOWED_SCHEMES);

        // An `id` in user content can shadow application markup and break DOM lookups.
        $config->set('Attr.EnableID', false);
        // Drop an <img> whose src did not survive scheme filtering instead of rendering
        // a broken element that still carries the author's alt text as bait.
        $config->set('Core.RemoveInvalidImg', true);
        // Comments are not documents: no author-supplied comment survives.
        $config->set('HTML.AllowedComments', []);

        $cache = $this->cacheDirectory();

        if ($cache !== null) {
            $config->set('Cache.SerializerPath', $cache);
            $config->set('Cache.SerializerPermissions', 0755);
        } else {
            // Shared hosting with a read-only storage directory must still sanitise;
            // rebuilding the definition each time is slower, never unsafe.
            $config->set('Cache.DefinitionImpl', null);
        }

        return $config;
    }

    /**
     * The serializer cache directory, created on first use, or null when it cannot be
     * used at all.
     */
    private function cacheDirectory(): ?string
    {
        $path = storage_path('app/purifier');

        if (! is_dir($path) && ! @mkdir($path, 0755, true) && ! is_dir($path)) {
            return null;
        }

        return is_writable($path) ? $path : null;
    }

    /**
     * Rewrite every anchor so its `rel` and `target` are ours rather than the author's.
     *
     * HTMLPurifier ships transforms for this, but they apply only to links it judges
     * outbound; the guarantee here has to be unconditional. Pattern matching is safe at
     * this point precisely because the input is HTMLPurifier's own normalised output —
     * well-formed tags, double-quoted values, entity-encoded — and the allow-list has
     * already reduced `a` to `href` and `title`, so rebuilding the tag loses nothing.
     */
    private function forceLinkRelations(string $html): string
    {
        if (stripos($html, '<a') === false) {
            return $html;
        }

        $rewritten = preg_replace_callback(
            '/<a\b([^>]*)>/i',
            static function (array $match): string {
                if (preg_match('/\bhref="([^"]*)"/i', $match[1], $href) !== 1) {
                    return '<a>';
                }

                $title = preg_match('/\btitle="([^"]*)"/i', $match[1], $found) === 1
                    ? ' title="'.$found[1].'"'
                    : '';

                return '<a href="'.$href[1].'"'.$title
                    .' rel="'.self::LINK_REL.'" target="_blank">';
            },
            $html,
        );

        return $rewritten ?? $html;
    }
}
