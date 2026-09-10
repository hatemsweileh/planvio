<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The stored-XSS control (docs/SECURITY.md, threat 3).
 *
 * Every assertion here is a negative one: the payload must not survive into the database.
 * A regression in this file is a stored-XSS vulnerability in comments and wiki pages, not
 * a formatting bug, so the cases cover the obfuscations an allow-list is expected to
 * defeat as well as the obvious ones.
 */
final class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new HtmlSanitizer;
    }

    /* ------------------------------------------------------------------ *
     * Script injection
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_removes_script_elements_and_their_contents(): void
    {
        $clean = $this->sanitizer->sanitize('<p>Hi</p><script>alert(document.cookie)</script>');

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('alert', $clean);
        $this->assertSame('<p>Hi</p>', $clean);
    }

    #[Test]
    public function it_removes_uppercase_and_split_script_tags(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<SCRIPT SRC="//evil.example/x.js"></SCRIPT><scr<script>ipt>alert(1)</script>',
        );

        $this->assertStringNotContainsStringIgnoringCase('<script', $clean);
        $this->assertStringNotContainsString('evil.example', $clean);

        // What is left of the nested tag is inert text: the angle bracket that would have
        // closed a tag is entity-encoded, so nothing here can start an element.
        $this->assertSame('ipt&gt;alert(1)', $clean);
    }

    /* ------------------------------------------------------------------ *
     * Event handlers
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_removes_the_onerror_handler_from_an_image(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<img src="https://example.com/a.png" alt="a" onerror="alert(1)">',
        );

        $this->assertStringNotContainsStringIgnoringCase('onerror', $clean);
        $this->assertStringNotContainsString('alert(1)', $clean);
        $this->assertStringContainsString('src="https://example.com/a.png"', $clean);
    }

    #[Test]
    #[DataProvider('eventHandlerPayloads')]
    public function it_removes_every_event_handler_attribute(string $payload, string $handler): void
    {
        $clean = $this->sanitizer->sanitize($payload);

        $this->assertStringNotContainsStringIgnoringCase($handler, $clean);
        $this->assertStringNotContainsString('alert', $clean);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function eventHandlerPayloads(): array
    {
        return [
            'onclick' => ['<div onclick="alert(1)">x</div>', 'onclick'],
            'onload' => ['<div onload="alert(1)">x</div>', 'onload'],
            'onmouseover' => ['<span onmouseover="alert(1)">x</span>', 'onmouseover'],
            'onfocus unquoted' => ['<a href="https://a.example" onfocus=alert(1) autofocus>x</a>', 'onfocus'],
            'onerror mixed case' => ['<img src="https://a.example/a.png" alt="a" OnErRoR="alert(1)">', 'onerror'],
            'onanimationstart' => ['<p onanimationstart="alert(1)">x</p>', 'onanimationstart'],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Dangerous URI schemes
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_removes_a_javascript_href(): void
    {
        $clean = $this->sanitizer->sanitize('<a href="javascript:alert(1)">click</a>');

        $this->assertStringNotContainsStringIgnoringCase('javascript:', $clean);
        $this->assertSame('<a>click</a>', $clean);
    }

    #[Test]
    #[DataProvider('dangerousSchemePayloads')]
    public function it_removes_every_non_browsable_scheme(string $payload, string $needle): void
    {
        $clean = $this->sanitizer->sanitize($payload);

        $this->assertStringNotContainsStringIgnoringCase($needle, $clean);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function dangerousSchemePayloads(): array
    {
        return [
            'javascript mixed case' => ['<a href="JaVaScRiPt:alert(1)">x</a>', 'javascript'],
            'javascript with padding' => ['<a href="  javascript:alert(1)">x</a>', 'javascript'],
            'javascript on an image' => ['<img src="javascript:alert(1)" alt="x">', 'javascript'],
            'vbscript' => ['<a href="vbscript:msgbox(1)">x</a>', 'vbscript'],
            'data uri image' => ['<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=" alt="x">', 'data:'],
            'data uri link' => ['<a href="data:text/html,PHNjcmlwdD4=">x</a>', 'data:'],
            'file scheme' => ['<a href="file:///etc/passwd">x</a>', 'file:'],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Elements outside the allow-list
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_removes_an_iframe(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<p>before</p><iframe src="https://evil.example"></iframe><p>after</p>',
        );

        $this->assertStringNotContainsString('<iframe', $clean);
        $this->assertStringNotContainsString('evil.example', $clean);
        $this->assertSame('<p>before</p><p>after</p>', $clean);
    }

    #[Test]
    #[DataProvider('disallowedElementPayloads')]
    public function it_removes_elements_outside_the_allow_list(string $payload, string $tag): void
    {
        $clean = $this->sanitizer->sanitize($payload);

        $this->assertStringNotContainsStringIgnoringCase('<'.$tag, $clean);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function disallowedElementPayloads(): array
    {
        return [
            'object' => ['<object data="https://evil.example/x.swf"></object>', 'object'],
            'embed' => ['<embed src="https://evil.example/x.swf">', 'embed'],
            'form' => ['<form action="https://evil.example"><input name="a"></form>', 'form'],
            'input' => ['<input type="text" name="password">', 'input'],
            'style element' => ['<style>body{display:none}</style>', 'style'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example">', 'meta'],
            'base' => ['<base href="https://evil.example/">', 'base'],
            'svg' => ['<svg><circle r="1"></circle></svg>', 'svg'],
            'math' => ['<math><mtext>x</mtext></math>', 'math'],
            'link' => ['<link rel="stylesheet" href="https://evil.example/x.css">', 'link'],
            'audio' => ['<audio src="https://evil.example/a.mp3"></audio>', 'audio'],
            'video' => ['<video src="https://evil.example/a.mp4"></video>', 'video'],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Style attributes
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_removes_the_style_attribute(): void
    {
        $clean = $this->sanitizer->sanitize('<p style="position:fixed;top:0;left:0">overlay</p>');

        $this->assertStringNotContainsString('style', $clean);
        $this->assertSame('<p>overlay</p>', $clean);
    }

    #[Test]
    public function it_removes_a_style_attribute_carrying_a_javascript_url(): void
    {
        $clean = $this->sanitizer->sanitize('<div style="background:url(javascript:alert(1))">x</div>');

        $this->assertStringNotContainsString('style', $clean);
        $this->assertStringNotContainsStringIgnoringCase('javascript', $clean);
    }

    #[Test]
    public function it_removes_class_and_id_attributes(): void
    {
        $clean = $this->sanitizer->sanitize('<div class="p-4" id="app">x</div>');

        $this->assertSame('<div>x</div>', $clean);
    }

    /* ------------------------------------------------------------------ *
     * Links
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_forces_rel_and_target_on_every_link(): void
    {
        $clean = $this->sanitizer->sanitize('<a href="https://example.com">x</a>');

        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $clean);
        $this->assertStringContainsString('target="_blank"', $clean);
    }

    #[Test]
    public function it_replaces_an_author_supplied_rel_rather_than_appending_to_it(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<a href="https://example.com" rel="dofollow" target="_self">x</a>',
        );

        $this->assertStringNotContainsString('dofollow', $clean);
        $this->assertStringNotContainsString('_self', $clean);
        $this->assertSame(
            '<a href="https://example.com" rel="noopener noreferrer nofollow" target="_blank">x</a>',
            $clean,
        );
    }

    #[Test]
    public function it_keeps_mailto_links(): void
    {
        $clean = $this->sanitizer->sanitize('<a href="mailto:someone@example.com">mail</a>');

        $this->assertStringContainsString('href="mailto:someone@example.com"', $clean);
    }

    /* ------------------------------------------------------------------ *
     * Content that must survive
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_keeps_the_allowed_formatting_vocabulary(): void
    {
        $markup = '<h1>Title</h1><h2>Sub</h2><h3>Sub sub</h3>'
            .'<p><strong>b</strong><em>i</em><u>u</u><s>s</s><span>span</span></p>'
            .'<ul><li>one</li></ul><ol><li>two</li></ol>'
            .'<blockquote><pre><code>code</code></pre></blockquote><hr>'
            .'<table><thead><tr><th colspan="2">head</th></tr></thead>'
            .'<tbody><tr><td rowspan="2">cell</td></tr></tbody></table>'
            .'<div>div</div><img src="/storage/a.png" alt="a">';

        $clean = $this->sanitizer->sanitize($markup);

        $expected = [
            '<h1>', '<h2>', '<h3>', '<strong>', '<em>', '<u>', '<s>', '<span>', '<ul>',
            '<ol>', '<li>', '<blockquote>', '<pre>', '<code>', '<hr', '<table>', '<thead>',
            '<tbody>', '<tr>', 'colspan="2"', 'rowspan="2"', '<div>', 'src="/storage/a.png"',
        ];

        foreach ($expected as $fragment) {
            $this->assertStringContainsString($fragment, $clean, "Lost {$fragment} from allowed markup.");
        }
    }

    #[Test]
    public function it_returns_an_empty_string_when_nothing_survives(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize('<script>alert(1)</script>'));
        $this->assertSame('', $this->sanitizer->sanitize('   '));
        $this->assertSame('', $this->sanitizer->sanitize(''));
    }

    #[Test]
    public function it_keeps_escaped_angle_brackets_escaped(): void
    {
        $clean = $this->sanitizer->sanitize('<p>5 &lt; 6 &amp; 7 &gt; 6</p>');

        $this->assertStringContainsString('&lt;', $clean);
        $this->assertStringContainsString('&amp;', $clean);
    }

    /* ------------------------------------------------------------------ *
     * Excerpts
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_reduces_an_excerpt_to_plain_text(): void
    {
        $excerpt = $this->sanitizer->sanitizeExcerpt(
            '<p>Hello <strong>world</strong></p><script>alert(1)</script>',
            100,
        );

        $this->assertSame('Hello world', $excerpt);
        $this->assertStringNotContainsString('<', $excerpt);
        $this->assertStringNotContainsString('alert', $excerpt);
    }

    #[Test]
    public function it_separates_block_elements_in_an_excerpt(): void
    {
        $this->assertSame('One Two', $this->sanitizer->sanitizeExcerpt('<p>One</p><p>Two</p>', 100));
    }

    #[Test]
    public function it_truncates_an_excerpt_to_the_requested_length(): void
    {
        $excerpt = $this->sanitizer->sanitizeExcerpt('<p>'.str_repeat('word ', 40).'</p>', 20);

        $this->assertLessThanOrEqual(21, mb_strlen($excerpt));
        $this->assertStringEndsWith('…', $excerpt);
    }

    #[Test]
    public function it_returns_an_empty_excerpt_for_a_non_positive_length(): void
    {
        $this->assertSame('', $this->sanitizer->sanitizeExcerpt('<p>Hello</p>', 0));
        $this->assertSame('', $this->sanitizer->sanitizeExcerpt('<p>Hello</p>', -5));
    }

    #[Test]
    public function it_never_leaks_markup_through_an_excerpt(): void
    {
        $excerpt = $this->sanitizer->sanitizeExcerpt(
            '<p>a</p><iframe src="https://evil.example"></iframe><a href="javascript:alert(1)">b</a>',
            100,
        );

        $this->assertSame('a b', $excerpt);
    }
}
