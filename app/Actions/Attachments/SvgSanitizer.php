<?php

declare(strict_types=1);

namespace App\Actions\Attachments;

use DOMAttr;
use DOMDocument;
use DOMDocumentType;
use DOMElement;
use DOMXPath;

/**
 * Strips the executable parts out of an uploaded SVG.
 *
 * An SVG is a document, not a picture: it can carry `<script>`, event handlers, an
 * `<foreignObject>` holding arbitrary HTML, and `javascript:` references. Rendered inside
 * an `<img>` most of that is inert, but the moment a file is opened in its own tab — which
 * is exactly what "open in new tab" on an attachment does — it runs with the origin of
 * whoever opened it. So SVGs are rewritten on the way in and the sanitised bytes are what
 * gets stored; the original is never written to disk.
 *
 * Anything that is not recognisably an SVG document is refused rather than repaired.
 */
final class SvgSanitizer
{
    /**
     * Elements removed with their entire subtree.
     *
     * `foreignObject` escapes SVG into HTML. The animation elements are here because
     * `<set attributeName="href" to="javascript:…">` is a script tag written sideways.
     */
    private const FORBIDDEN_ELEMENTS = [
        'script', 'foreignobject', 'iframe', 'embed', 'object', 'handler', 'listener',
        'set', 'animate', 'animatetransform', 'animatemotion', 'animatecolor',
    ];

    /**
     * Schemes a reference inside the document may use. `data:` is absent on purpose: a
     * data URI can carry a second SVG, script and all, past a check that only looked at
     * the outer document.
     */
    private const ALLOWED_REFERENCE_SCHEMES = ['http', 'https'];

    /** Style values holding any of these are dropped rather than parsed. */
    private const FORBIDDEN_STYLE_FRAGMENTS = ['javascript:', 'expression(', '@import', 'data:', 'behavior:', '-moz-binding'];

    /**
     * @return string the sanitised document, or null when the input is not an SVG at all
     */
    public function sanitize(string $svg): ?string
    {
        $svg = self::stripByteOrderMark($svg);

        if (trim($svg) === '') {
            return null;
        }

        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;

        $previous = libxml_use_internal_errors(true);

        // LIBXML_NOENT is deliberately absent: without it internal entities are left
        // unexpanded, which is what stops a billion-laughs payload from being expanded
        // here rather than in the browser.
        $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false || ! $document->documentElement instanceof DOMElement) {
            return null;
        }

        if (strtolower($document->documentElement->localName) !== 'svg') {
            return null;
        }

        self::removeDoctype($document);
        self::removeForbiddenElements($document);
        self::cleanAttributes($document);

        $output = $document->saveXML();

        return $output === false ? null : $output;
    }

    private static function stripByteOrderMark(string $svg): string
    {
        return str_starts_with($svg, "\xEF\xBB\xBF") ? substr($svg, 3) : $svg;
    }

    /**
     * A DOCTYPE in an uploaded file exists to declare entities, and we have no use for the
     * ones it could declare.
     */
    private static function removeDoctype(DOMDocument $document): void
    {
        foreach (iterator_to_array($document->childNodes) as $node) {
            if ($node instanceof DOMDocumentType) {
                $document->removeChild($node);
            }
        }
    }

    private static function removeForbiddenElements(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $elements = $xpath->query('//*');

        if ($elements === false) {
            return;
        }

        $doomed = [];

        foreach ($elements as $element) {
            if ($element instanceof DOMElement
                && in_array(strtolower($element->localName), self::FORBIDDEN_ELEMENTS, true)) {
                $doomed[] = $element;
            }
        }

        foreach ($doomed as $element) {
            $element->parentNode?->removeChild($element);
        }
    }

    private static function cleanAttributes(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $elements = $xpath->query('//*');

        if ($elements === false) {
            return;
        }

        foreach ($elements as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
                if ($attribute instanceof DOMAttr && ! self::attributeIsSafe($attribute)) {
                    $element->removeAttributeNode($attribute);
                }
            }
        }
    }

    private static function attributeIsSafe(DOMAttr $attribute): bool
    {
        $name = strtolower($attribute->localName);
        $value = (string) $attribute->value;

        // Every `on*` handler, whatever the namespace it was smuggled in under.
        if (str_starts_with($name, 'on')) {
            return false;
        }

        if ($name === 'style') {
            $lower = strtolower($value);

            foreach (self::FORBIDDEN_STYLE_FRAGMENTS as $fragment) {
                if (str_contains($lower, $fragment)) {
                    return false;
                }
            }

            return true;
        }

        if (in_array($name, ['href', 'src', 'from', 'to', 'values', 'begin', 'end', 'attributename'], true)) {
            return self::referenceIsSafe($value);
        }

        return true;
    }

    /**
     * Internal references (`#id`) and same-origin paths are fine; anything with a scheme
     * has to be one we allow.
     */
    private static function referenceIsSafe(string $value): bool
    {
        // Whitespace and control characters inside a URL are how `java\nscript:` gets past
        // a naive prefix check, so they are removed before the scheme is read.
        $normalised = strtolower((string) preg_replace('/[\s\x00-\x20\x7F]+/u', '', $value));

        if ($normalised === '' || str_starts_with($normalised, '#')) {
            return true;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $normalised, $match) !== 1) {
            // No scheme at all: a relative path, which resolves against our own origin.
            return true;
        }

        return in_array($match[1], self::ALLOWED_REFERENCE_SCHEMES, true);
    }
}
