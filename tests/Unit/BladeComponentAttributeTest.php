<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A Blade directive inside an `<x-…>` component tag is never compiled, and nothing says so.
 *
 * Blade's component tag compiler runs before the directive compiler and rewrites the whole
 * tag into a `@component` call, treating everything between `<x-` and `>` as an attribute
 * string. A `@js(...)`, `@checked(...)` or `@class(...)` inside that string is copied
 * through verbatim — into a JavaScript expression, or into the rendered HTML — and Blade
 * reports nothing, because as far as it is concerned there was no directive there to
 * compile.
 *
 * The failure is entirely at runtime and entirely in the browser:
 *
 *   - `x-on:click="…{ prompt: @js($p) }"` reaches Alpine as the literal characters
 *     `@js($p)` and throws `SyntaxError: Invalid or unexpected token`, so the control does
 *     nothing at all when clicked;
 *   - `<x-ui.checkbox @checked($on)>` defeats the tag compiler outright, so the literal
 *     `<x-ui.checkbox>` element reaches the DOM and the control is simply absent.
 *
 * Neither raises a PHP error, so neither can fail a rendering test: the view compiles, the
 * response is 200, and the assertion that the page contains the surrounding markup passes.
 * Three of these shipped — the saved-views share checkbox, the task AI actions menu and the
 * wiki summarise menu — and all three were found by opening a browser and reading its
 * console, which is not a thing a test suite does.
 *
 * So this asserts the shape statically instead. It is a cheap grep over every view, and it
 * is the only mechanism in the suite that can see this class of defect at all.
 *
 * The fix at each site is to write the directive's underlying call out longhand —
 * `{{ \Illuminate\Support\Js::from($value) }}` for `@js`, and a plain ternary for the
 * boolean attribute helpers. `{{ }}` renders a `Htmlable` unescaped, so the output is
 * identical to what the directive would have produced.
 */
final class BladeComponentAttributeTest extends TestCase
{
    /**
     * The directives that are worth failing a build over: each one is legal on an ordinary
     * HTML element, silently inert inside a component tag, and used somewhere in Planvio or
     * likely to be reached for.
     *
     * `@this` is deliberately absent — it is Livewire's, it is not a call, and it is
     * correct inside a component tag.
     */
    private const DIRECTIVES = [
        'js', 'checked', 'selected', 'disabled', 'readonly', 'required',
        'class', 'style', 'entangle', 'json', 'lang', 'method', 'csrf',
    ];

    #[Test]
    public function no_view_puts_a_blade_directive_inside_a_component_tag(): void
    {
        $offences = [];

        foreach (self::views() as $path) {
            $source = (string) file_get_contents($path);

            foreach (self::componentTags($source) as [$offset, $tag]) {
                $directive = self::directiveIn($tag);

                if ($directive === null) {
                    continue;
                }

                $line = substr_count($source, "\n", 0, $offset) + 1;
                $relative = str_replace('\\', '/', substr($path, strlen(self::viewRoot()) + 1));

                $offences[] = sprintf('%s:%d — @%s(…) inside <%s…>', $relative, $line, $directive, self::tagName($tag));
            }
        }

        $this->assertSame([], $offences, implode("\n", array_merge(
            ['A Blade directive inside a component tag is copied through uncompiled and fails only in the browser:'],
            $offences,
            ['', 'Write the underlying call out longhand instead — {{ \Illuminate\Support\Js::from($v) }} for @js.'],
        )));
    }

    /**
     * The detector, against the three defects that actually shipped and against the shapes
     * it must not flag.
     *
     * Without this, a scanner that had quietly stopped matching anything would still report
     * a clean tree, which is the same green as a clean tree and is the more likely of the
     * two failure modes.
     */
    #[Test]
    #[DataProvider('knownShapes')]
    public function the_detector_recognises_the_defect_and_only_the_defect(string $source, ?string $expected): void
    {
        $found = null;

        foreach (self::componentTags($source) as [, $tag]) {
            $found = self::directiveIn($tag) ?? $found;
        }

        $this->assertSame($expected, $found);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function knownShapes(): iterable
    {
        // The three that shipped.
        yield 'js in an alpine handler' => [
            '<x-ui.dropdown-item x-on:click="$dispatch(\'open\', { prompt: @js($action[\'prompt\']) })" />',
            'js',
        ];
        yield 'checked on a checkbox component' => [
            '<x-ui.checkbox wire:model="newViewShared" @checked($newViewShared) :label="__(\'Share\')" />',
            'checked',
        ];
        yield 'js spanning several lines' => [
            "<x-ui.button variant=\"secondary\"\n    x-on:click=\"open({\n        prompt: @js(__('Draft it.')),\n    })\">\n    Go\n</x-ui.button>",
            'js',
        ];

        // The shapes that are correct and must stay silent.
        yield 'the longhand fix' => [
            '<x-ui.button x-on:click="open({ prompt: {{ \Illuminate\Support\Js::from($p) }} })">Go</x-ui.button>',
            null,
        ];
        yield 'livewire @this is not a directive call' => [
            '<x-ui.button x-on:click="@this.call(\'save\')">Save</x-ui.button>',
            null,
        ];
        yield 'a directive on an ordinary element is fine' => [
            '<input type="checkbox" @checked($on)> <x-ui.button>Go</x-ui.button>',
            null,
        ];
        yield 'an escaped directive is literal text' => [
            '<x-ui.code>@@js($value)</x-ui.code>',
            null,
        ];
        yield 'a directive after the tag closes belongs to the slot' => [
            '<x-ui.button>@class([\'a\'])</x-ui.button>',
            null,
        ];
    }

    /**
     * Every `<x-…>` opening tag in a template, as `[offset, text]`.
     *
     * Hand-scanned rather than matched with one expression, because an attribute value may
     * legally contain a `>` — `x-show="a > b"` — and a regex that stops at the first one
     * cuts the tag in half and misses everything after it. Quote state is tracked instead,
     * which is what the tag compiler itself does.
     *
     * @return list<array{int, string}>
     */
    private static function componentTags(string $source): array
    {
        $tags = [];
        $offset = 0;
        $length = strlen($source);

        while (($start = strpos($source, '<x-', $offset)) !== false) {
            // Closing tags carry no attributes, so there is nothing in one to miscompile.
            if (substr($source, $start, 4) === '</x-') {
                $offset = $start + 4;

                continue;
            }

            $quote = null;
            $i = $start + 3;

            while ($i < $length) {
                $char = $source[$i];

                if ($quote !== null) {
                    if ($char === $quote) {
                        $quote = null;
                    }
                } elseif ($char === '"' || $char === "'") {
                    $quote = $char;
                } elseif ($char === '>') {
                    break;
                }

                $i++;
            }

            $tags[] = [$start, substr($source, $start, $i - $start + 1)];

            // `$i` lands on `$length` when an unterminated tag runs to the end of the file.
            // Advancing past it would hand strpos() an offset outside the haystack.
            $offset = min($i + 1, $length);

            if ($offset >= $length) {
                break;
            }
        }

        return $tags;
    }

    /**
     * The first uncompiled directive inside one tag, or null.
     */
    private static function directiveIn(string $tag): ?string
    {
        $names = implode('|', self::DIRECTIVES);

        // `(?<!@)` lets `@@js` through: that is Blade's own escape for a literal `@js`.
        if (preg_match('/(?<!@)@('.$names.')\s*\(/', $tag, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private static function tagName(string $tag): string
    {
        preg_match('/^<(x-[A-Za-z0-9.:_-]+)/', $tag, $match);

        return $match[1] ?? 'x-?';
    }

    private static function viewRoot(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';
    }

    /**
     * @return list<string>
     */
    private static function views(): array
    {
        $paths = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::viewRoot(), RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }
}
