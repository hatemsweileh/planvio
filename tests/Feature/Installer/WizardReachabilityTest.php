<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use App\Http\Middleware\EnsureInstalled;
use App\Services\Install\AdministratorAccount;
use App\Services\Install\AiCredentials;
use App\Services\Install\ApplicationSettings;
use App\Services\Install\DatabaseCredentials;
use App\Services\Install\EnvFile;
use App\Services\Install\EnvWriter;
use App\Services\Install\InstallPlan;
use App\Services\Install\MailCredentials;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Whether the wizard can still be *used*, as opposed to merely rendered.
 *
 * Every other test in this suite drives the steps in process, through `Livewire::test()` and
 * the install engine directly. That proves the logic and proves nothing about the two things
 * a browser needs in order to reach that logic: a Livewire endpoint the install gate lets
 * through, and an application that goes on considering itself uninstalled until it is.
 *
 * Both were broken at once, and between them they made a browser installation impossible —
 * the first refused every step's submit, the second killed the session three steps into the
 * run. Neither could fail a test that never makes an HTTP request.
 */
final class WizardReachabilityTest extends InstallerTestCase
{
    /* ------------------------------------------------------------------ *
     * The endpoint every step submits to
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_install_gate_lets_livewire_through_on_the_path_livewire_actually_serves(): void
    {
        // Livewire 4 derives its whole endpoint prefix from APP_KEY — `/livewire-<8 hex>/…`
        // — so a hard-coded `livewire/*` exemption matches nothing and the wizard's own
        // `wire:click` is answered with a redirect back to the first screen.
        $path = ltrim(EndpointResolver::updatePath(), '/');

        $this->assertStringStartsWith('livewire-', $path);

        $passed = false;

        $response = (new EnsureInstalled)->handle(
            Request::create('/'.$path, 'POST'),
            function () use (&$passed): Response {
                $passed = true;

                return new Response;
            },
        );

        $this->assertTrue($passed, "EnsureInstalled refused Livewire's own endpoint [{$path}].");
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function the_install_gate_still_sends_an_ordinary_page_to_the_wizard(): void
    {
        $response = (new EnsureInstalled)->handle(
            Request::create('/w/acme/projects', 'GET'),
            fn (): Response => new Response,
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/install', (string) $response->headers->get('Location'));
    }

    /* ------------------------------------------------------------------ *
     * When the installation starts calling itself finished
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_env_file_written_at_step_two_does_not_declare_the_install_finished(): void
    {
        // `.env` is written at step two of twelve. APP_INSTALLED is an answer
        // EnsureInstalled accepts, so writing `true` here tells the whole application it is
        // installed with ten steps still to run: InstallerEnvironment stops pinning the
        // wizard to the file session driver and hands the next request to a `sessions` table
        // the migrations have not created.
        $values = $this->plan()->environmentValues('base64:'.base64_encode(str_repeat('k', 32)));

        $this->assertSame('false', $values['APP_INSTALLED']);
    }

    #[Test]
    public function the_lock_step_is_what_sets_app_installed(): void
    {
        $writer = $this->app->make(EnvWriter::class);

        $writer->write($this->plan(), 'base64:'.base64_encode(str_repeat('k', 32)));

        $this->assertSame('false', EnvFile::fromFile($this->paths->env)->get('APP_INSTALLED'));
        $this->assertFalse(EnsureInstalled::isInstalled());

        $this->assertTrue($writer->markInstalled());
        $this->assertSame('true', EnvFile::fromFile($this->paths->env)->get('APP_INSTALLED'));
    }

    #[Test]
    public function marking_the_installation_finished_reports_failure_rather_than_throwing(): void
    {
        // The lock already exists by the time this is called, so a `.env` that has become
        // unwritable costs a warning and never the installation.
        $writer = $this->app->make(EnvWriter::class);

        @unlink($this->paths->env);

        $this->assertFalse($writer->markInstalled());
    }

    private function plan(): InstallPlan
    {
        return new InstallPlan(
            database: DatabaseCredentials::mysql('127.0.0.1', 3306, 'planvio', 'planvio', 'secret'),
            application: new ApplicationSettings(
                name: 'Acme Projects',
                url: 'http://acme.test',
                timezone: 'UTC',
                locale: 'en',
                currency: 'USD',
                dateFormat: 'Y-m-d',
                environment: ApplicationSettings::ENVIRONMENT_PRODUCTION,
            ),
            administrator: new AdministratorAccount('Ada', 'ada@acme.test', 'Correct-Horse-9'),
            mail: MailCredentials::skipped('ada@acme.test', 'Acme Projects'),
            ai: AiCredentials::disabled(),
        );
    }
}
