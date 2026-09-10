<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * An overlay anchored to a trigger must not be positioned with `absolute`.
 *
 * This is the shape of a bug that is invisible in review and obvious in use. An
 * `absolute` panel is painted inside its nearest positioned ancestor, so the moment a
 * scroller appears anywhere above it — a sidebar with `overflow-y-auto`, a board column,
 * a table that scrolls sideways, the task drawer — the panel is clipped at that
 * ancestor's edge rather than the window's. The markup is unchanged and looks correct;
 * only the rendering is wrong, and only on the screens where the ancestor exists. On the
 * project board there are 53 such overlays and every one of them had a clipping ancestor.
 *
 * Anchored overlays therefore go in the top layer, via `.pv-overlay` and the Popover API,
 * and are placed against the viewport by `Alpine.data('anchored')` — see
 * resources/js/app.js. This asserts that no view quietly goes back to the old way.
 *
 * Full-screen overlays are a different thing and are allowed: a modal or a drawer covers
 * the viewport with `fixed inset-0`, has nothing to be anchored to and nothing to escape
 * from, and its inner backdrop is legitimately `absolute` inside that fixed root.
 */
final class OverlayContainmentTest extends TestCase
{
    /**
     * A z-index this high on a positioned element means "float above the page", which is
     * the whole population this rule is about. Lower layers are in-flow decoration —
     * a sticky header, a selected card's ring — and are not overlays.
     *
     * @var list<string>
     */
    private const OVERLAY_LAYERS = ['z-30', 'z-40', 'z-50'];

    /**
     * Components that legitimately own a full-screen fixed root.
     *
     * @var list<string>
     */
    private const FULL_SCREEN = [
        'components/ui/modal.blade.php',
        'components/ui/drawer.blade.php',
    ];

    #[Test]
    public function no_anchored_overlay_is_positioned_with_absolute(): void
    {
        $offences = [];

        foreach (self::views() as $path => $source) {
            if (self::isFullScreenComponent($path)) {
                continue;
            }

            foreach (self::classAttributes($source) as [$line, $classes]) {
                if (! str_contains($classes, 'absolute')) {
                    continue;
                }

                $layer = self::overlayLayerIn($classes);

                if ($layer === null) {
                    continue;
                }

                // `absolute inset-0` is a backdrop or a stretched hit area, not a panel:
                // it cannot overflow anything, because it is exactly its parent's size.
                if (str_contains($classes, 'inset-0')) {
                    continue;
                }

                $offences[] = sprintf('%s:%d — %s with `absolute`', $path, $line, $layer);
            }
        }

        $this->assertSame([], $offences, implode("\n", array_merge(
            ['An overlay positioned with `absolute` is clipped by any scrolling ancestor.'],
            $offences,
            ['', 'Anchor it instead: x-data="anchored({...})" on the wrapper, and'],
            ['`popover="manual" class="pv-overlay"` on the panel. See resources/js/app.js.'],
        )));
    }

    /**
     * The primitives the rule depends on must exist, or the rule above passes by
     * describing nothing.
     */
    #[Test]
    public function the_anchored_overlay_primitive_is_present(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/app.js');
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

        $this->assertStringContainsString("Alpine.data('anchored'", $js, 'The anchored overlay behaviour is gone.');
        $this->assertStringContainsString('showPopover', $js, 'Overlays are no longer put in the top layer.');
        $this->assertStringContainsString('.pv-overlay', $css, 'The overlay styles are gone.');

        // The two halves of the containment promise: escape the clip, stay in the window.
        $this->assertStringContainsString('placeFloating', $js, 'The viewport placement routine is gone.');
    }

    #[Test]
    public function the_shared_components_use_the_anchored_primitive(): void
    {
        foreach (['tooltip', 'dropdown'] as $component) {
            $source = (string) file_get_contents(
                dirname(__DIR__, 2)."/resources/views/components/ui/{$component}.blade.php",
            );

            $this->assertStringContainsString('anchored(', $source, "The {$component} no longer anchors itself.");
            $this->assertStringContainsString('pv-overlay', $source, "The {$component} panel is not an overlay.");
        }
    }

    private static function isFullScreenComponent(string $relative): bool
    {
        foreach (self::FULL_SCREEN as $allowed) {
            if (str_ends_with($relative, $allowed)) {
                return true;
            }
        }

        return false;
    }

    private static function overlayLayerIn(string $classes): ?string
    {
        foreach (self::OVERLAY_LAYERS as $layer) {
            if (preg_match('/(?<![\w-])'.preg_quote($layer, '/').'(?![\w-])/', $classes) === 1) {
                return $layer;
            }
        }

        return null;
    }

    /**
     * Every `class="…"` in a template, with the line it is on.
     *
     * Blade interpolation inside the value is left as written — the rule is about literal
     * utilities, and a computed class name is not something a scanner can resolve anyway.
     *
     * @return list<array{int, string}>
     */
    private static function classAttributes(string $source): array
    {
        if (preg_match_all('/\bclass="([^"]*)"/s', $source, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $found = [];

        foreach ($matches[1] as $match) {
            [$value, $offset] = $match;
            $found[] = [substr_count($source, "\n", 0, $offset) + 1, $value];
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    private static function views(): array
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';
        $sources = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $sources[$relative] = (string) file_get_contents($file->getPathname());
        }

        ksort($sources);

        return $sources;
    }
}
