<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use App\Enums\AiDriver;
use App\Enums\AiMode;
use App\Services\Install\AiCredentials;
use App\Services\Install\AiTester;
use App\Services\Install\MailCredentials;
use App\Services\Install\MailTester;
use App\Services\Install\RequirementStatus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two remaining "Test …" buttons: SMTP and the AI provider.
 *
 * Both are optional steps, so the interesting property is the same in each case — a failure
 * has to be a sentence in the panel and never an exception, and the credential that was
 * rejected must not appear anywhere in what comes back.
 */
final class TestConnectionsTest extends InstallerTestCase
{
    /* ------------------------------------------------------------------ *
     * Email
     * ------------------------------------------------------------------ */

    #[Test]
    public function an_unreachable_smtp_server_is_reported_in_plain_language(): void
    {
        $result = (new MailTester)->test($this->smtp(port: 1), 'ada@example.com');

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('could not reach', $result->message);

        foreach (['Exception', 'smtp-secret', 'Stack trace'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $result->message);
            $this->assertStringNotContainsString($forbidden, (string) $result->detail);
        }
    }

    #[Test]
    public function a_skipped_mail_configuration_cannot_be_tested(): void
    {
        $result = (new MailTester)->test(MailCredentials::skipped('ada@example.com', 'Acme'), 'ada@example.com');

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('SMTP host', $result->message);
    }

    #[Test]
    public function a_test_message_needs_somewhere_to_go(): void
    {
        $result = (new MailTester)->test($this->smtp(), 'not-an-address');

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('valid address', $result->message);
    }

    #[Test]
    public function testing_email_never_becomes_the_installations_mailer(): void
    {
        $before = config('mail.mailers.smtp');

        (new MailTester)->test($this->smtp(port: 1), 'ada@example.com');

        $this->assertSame($before, config('mail.mailers.smtp'), 'A rejected test must not be applied.');
    }

    /* ------------------------------------------------------------------ *
     * AI
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_reachable_provider_passes(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 1],
            ]),
        ]);

        $result = $this->ai()->test($this->openAi('sk-planvio-installer-test-key'));

        $this->assertTrue($result->ok());
        $this->assertSame(RequirementStatus::Pass, $result->status);
    }

    #[Test]
    public function a_rejected_key_is_reported_without_repeating_the_key(): void
    {
        $key = 'sk-planvio-installer-test-key';

        // A 401 body is the single most likely place for an endpoint to echo the credential
        // it just refused, so the fake does exactly that.
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => ['message' => "Incorrect API key provided: {$key}. Check your key."],
            ], 401),
        ]);

        $result = $this->ai()->test($this->openAi($key));

        $this->assertFalse($result->ok());
        $this->assertNotSame('', $result->message);
        $this->assertStringNotContainsString($key, $result->message);
        $this->assertStringNotContainsString($key, (string) $result->detail);
    }

    #[Test]
    public function a_provider_that_needs_an_endpoint_is_refused_before_any_request_is_made(): void
    {
        // No Http::fake here on purpose: preventStrayRequests() would fail the test if this
        // reached the network, which is the assertion.
        $result = $this->ai()->test(AiCredentials::enabled(
            driver: AiDriver::OpenAiCompatible,
            baseUrl: null,
            apiKey: 'sk-planvio-installer-test-key',
            model: 'llama-3.1-70b',
            mode: AiMode::Assistant,
        ));

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('endpoint', $result->message);
    }

    #[Test]
    public function testing_ai_while_it_is_switched_off_says_so_rather_than_failing(): void
    {
        $result = $this->ai()->test(AiCredentials::disabled());

        $this->assertTrue($result->ok());
        $this->assertSame(RequirementStatus::Warn, $result->status);
    }

    /* ------------------------------------------------------------------ *
     * Fixtures
     * ------------------------------------------------------------------ */

    private function smtp(int $port = 587): MailCredentials
    {
        return MailCredentials::smtp(
            host: '127.0.0.1',
            port: $port,
            encryption: MailCredentials::ENCRYPTION_NONE,
            username: 'planvio@example.com',
            password: 'smtp-secret',
            fromAddress: 'planvio@example.com',
            fromName: 'Acme Projects',
        );
    }

    private function openAi(string $key): AiCredentials
    {
        return AiCredentials::enabled(
            driver: AiDriver::OpenAi,
            baseUrl: 'https://api.openai.com/v1',
            apiKey: $key,
            model: 'gpt-4o-mini',
            mode: AiMode::Assistant,
        );
    }

    private function ai(): AiTester
    {
        return $this->app->make(AiTester::class);
    }
}
