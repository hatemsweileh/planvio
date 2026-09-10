<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Support\Redactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Redaction is the last line before a secret becomes durable.
 *
 * `App\Support\Redactor` already decides by key. These tests cover what the AI layer adds:
 * deciding by shape, so a credential under an innocuous name is still caught, and truncating
 * so one oversized value cannot dominate an audit row.
 *
 * @fixture-secrets Every credential below is invented. Deciding by shape cannot be tested
 * without strings of that shape, so this file necessarily contains things that look like an
 * OpenAI key, an Anthropic key, a GitHub token, a Google key and an AWS access key — the
 * last is the example key from Amazon's own documentation. The release script's secret
 * sweep reads this marker and skips the file; it counts only under `tests/`, so the same
 * line in application code would not exempt anything.
 */
final class RedactorTest extends TestCase
{
    #[Test]
    public function it_still_redacts_by_key(): void
    {
        $redacted = (new Redactor)->redactArray([
            'api_key' => 'anything at all',
            'authorization' => 'Bearer abc',
            'nested' => ['client_secret' => 'shh'],
        ]);

        $this->assertSame('[redacted]', $redacted['api_key']);
        $this->assertSame('[redacted]', $redacted['authorization']);
        $this->assertSame('[redacted]', $redacted['nested']['client_secret']);
    }

    #[Test]
    public function it_redacts_credential_shaped_values_under_innocuous_keys(): void
    {
        $redactor = new Redactor;

        $shapes = [
            'sk-proj-Zx9QwErTy0123456789AbCdEfGhIjKlMnOpQrSt',
            'sk-ant-api03-abcdefghijklmnopqrstuvwxyz0123456789',
            'ghp_A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8',
            'xoxb-1234567890-abcdefghijklmnop',
            'AIzaSyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q',
            'AKIAIOSFODNN7EXAMPLE',
            'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk',
            'https://user:hunter2@internal.example.com/v1',
            '5f4dcc3b5aa765d61d8327deb882cf9928e4f2b1',
        ];

        foreach ($shapes as $shape) {
            $result = $redactor->redactArray(['note' => 'value is '.$shape.' end']);

            $this->assertStringNotContainsString($shape, $result['note'], "Unredacted: {$shape}");
            $this->assertStringContainsString('[redacted]', $result['note']);
            $this->assertTrue($redactor->containsSecret($shape));
        }
    }

    #[Test]
    public function it_leaves_ordinary_workspace_text_alone(): void
    {
        $redactor = new Redactor;

        $harmless = [
            'Ship the pricing page by Friday',
            'PRC-14 Renew the certificate',
            'https://planvio.test/w/acme/projects/12',
            'the-quarterly-marketing-campaign-retrospective',
            'Budget is 12500.00 EUR',
        ];

        foreach ($harmless as $text) {
            $this->assertSame($text, $redactor->redactString($text), "Wrongly redacted: {$text}");
        }
    }

    #[Test]
    public function it_truncates_to_the_configured_argument_limit(): void
    {
        config()->set('ai.logging.max_stored_argument_chars', 50);

        $result = (new Redactor)->redactString(str_repeat('x', 500));

        $this->assertStringEndsWith('... [truncated]', $result);
        $this->assertLessThan(500, mb_strlen($result));
    }

    #[Test]
    public function it_scrubs_before_it_truncates(): void
    {
        // Truncating first could cut a key in half and leave the readable part behind.
        config()->set('ai.logging.max_stored_argument_chars', 40);

        $result = (new Redactor)->redactString('sk-proj-Zx9QwErTy0123456789AbCdEfGhIjKlMnOpQrSt and more');

        $this->assertStringNotContainsString('sk-proj-Zx9', $result);
    }

    #[Test]
    public function it_preserves_the_type_it_was_given(): void
    {
        $redactor = new Redactor;

        $this->assertIsString($redactor->redact('plain text'));
        $this->assertIsArray($redactor->redact(['a' => 1]));
    }

    #[Test]
    public function it_keeps_non_string_scalars_intact(): void
    {
        $result = (new Redactor)->redactArray([
            'tokens_in' => 811,
            'ok' => true,
            'missing' => null,
            'items' => [1, 2, 3],
        ]);

        // `tokens_in` contains "token" but is a counter the usage rollup depends on.
        $this->assertSame(811, $result['tokens_in']);
        $this->assertTrue($result['ok']);
        $this->assertNull($result['missing']);
        $this->assertSame([1, 2, 3], $result['items']);
    }
}
