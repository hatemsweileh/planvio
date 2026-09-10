<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use App\Livewire\Installer\Administrator;
use App\Livewire\Installer\Ai;
use App\Livewire\Installer\Application;
use App\Livewire\Installer\Email;
use App\Services\Install\InstallState;
use App\Services\Install\InstallStep;
use App\Services\Install\WizardStep;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * Walking the wizard.
 *
 * Two things are being asserted throughout: that a screen cannot be opened before the ones it
 * depends on, and that the credentials collected on the way never come back out — the
 * administrator's password is not re-rendered into the form, and the AI key is never sent to
 * the browser again once it has been stored (spec §72).
 */
final class WizardFlowTest extends InstallerTestCase
{
    #[Test]
    public function a_later_screen_sends_you_back_to_the_first_one_still_outstanding(): void
    {
        $this->get(route('install.administrator'))->assertRedirect(route('install.database'));
        $this->get(route('install.ai'))->assertRedirect(route('install.database'));
        $this->get(route('install.install'))->assertRedirect(route('install.database'));

        $this->completeThrough(WizardStep::Database);

        $this->get(route('install.administrator'))->assertRedirect(route('install.application'));
        $this->get(route('install.application'))->assertOk();
    }

    #[Test]
    public function the_application_screen_suggests_values_and_lets_every_one_be_corrected(): void
    {
        $this->completeThrough(WizardStep::Database);

        $component = Livewire::withoutLazyLoading()->test(Application::class);

        // Detected, not assumed: the URL comes from the request the wizard was opened with.
        $component->assertSet('url', rtrim(url('/'), '/'));

        $component->set('name', 'Northwind')
            ->set('url', 'https://projects.northwind.test')
            ->set('timezone', 'Europe/Lisbon')
            ->set('currency', 'gbp')
            ->set('dateFormat', 'd/m/Y')
            ->set('environment', 'production')
            ->call('save')
            ->assertRedirect(route('install.administrator'));

        $saved = $this->state()->get(WizardStep::Application);

        $this->assertSame('Northwind', $saved['name']);
        $this->assertSame('https://projects.northwind.test', $saved['url']);
        $this->assertSame('GBP', $saved['currency']);
    }

    #[Test]
    public function the_application_address_has_to_be_an_absolute_http_url(): void
    {
        $this->completeThrough(WizardStep::Database);

        Livewire::test(Application::class)
            ->set('url', 'projects.northwind.test')
            ->call('save')
            ->assertHasErrors('url')
            ->assertNoRedirect();
    }

    #[Test]
    public function the_administrator_password_must_satisfy_the_configured_policy(): void
    {
        $this->completeThrough(WizardStep::Application);

        Livewire::test(Administrator::class)
            ->set('name', 'Ada Lovelace')
            ->set('email', 'ada@example.com')
            ->set('password', 'short')
            ->set('passwordConfirmation', 'short')
            ->call('save')
            ->assertHasErrors('password')
            ->assertNoRedirect();

        Livewire::test(Administrator::class)
            ->set('name', 'Ada Lovelace')
            ->set('email', 'ada@example.com')
            ->set('password', 'Correct Horse Battery 7')
            ->set('passwordConfirmation', 'Correct Horse Battery 8')
            ->call('save')
            ->assertHasErrors('password')
            ->assertNoRedirect();

        Livewire::test(Administrator::class)
            ->set('name', 'Ada Lovelace')
            ->set('email', 'Ada@Example.com')
            ->set('password', 'Correct Horse Battery 7')
            ->set('passwordConfirmation', 'Correct Horse Battery 7')
            ->call('save')
            ->assertRedirect(route('install.email'));

        $this->assertSame('ada@example.com', $this->state()->get(WizardStep::Administrator)['email']);
    }

    #[Test]
    public function the_administrator_password_is_never_rendered_back_into_the_form(): void
    {
        $this->completeThrough(WizardStep::Administrator);

        Livewire::test(Administrator::class)
            ->assertSet('name', 'Ada Lovelace')
            ->assertSet('password', '')
            ->assertSet('passwordConfirmation', '')
            ->assertDontSee('Correct Horse Battery 7');
    }

    #[Test]
    public function email_can_be_skipped_and_the_installation_falls_back_to_the_log_mailer(): void
    {
        $this->completeThrough(WizardStep::Administrator);

        Livewire::test(Email::class)
            ->call('skip')
            ->assertRedirect(route('install.ai'));

        $saved = $this->state()->get(WizardStep::Email);

        $this->assertFalse($saved['configured']);
        $this->assertSame('ada@example.com', $saved['from_address']);
    }

    #[Test]
    public function the_email_screen_asks_for_a_host_only_when_smtp_is_switched_on(): void
    {
        $this->completeThrough(WizardStep::Administrator);

        Livewire::test(Email::class)
            ->set('configured', true)
            ->set('host', '')
            ->call('save')
            ->assertHasErrors('host')
            ->assertNoRedirect();
    }

    #[Test]
    public function ai_can_be_skipped_and_the_wizard_still_reaches_the_install_step(): void
    {
        $this->completeThrough(WizardStep::Email);

        Livewire::test(Ai::class)
            ->assertSet('enabled', false)
            ->call('skip')
            ->assertRedirect(route('install.install'));

        $this->assertFalse($this->state()->get(WizardStep::Ai)['enabled']);
        $this->assertTrue($this->state()->isComplete());

        $plan = $this->state()->plan();

        $this->assertNotNull($plan);
        $this->assertFalse($plan->ai->enabled);
        $this->assertFalse($plan->mail->configured);
    }

    #[Test]
    public function a_stored_ai_key_is_never_sent_back_to_the_browser(): void
    {
        $this->completeThrough(WizardStep::Email);

        $key = 'sk-planvio-installer-9f2c4a1e7d3b';

        Livewire::test(Ai::class)
            ->set('enabled', true)
            ->set('driver', 'openai')
            ->set('baseUrl', 'https://api.openai.com/v1')
            ->set('model', 'gpt-4o-mini')
            ->set('apiKey', $key)
            ->call('save')
            ->assertRedirect(route('install.install'));

        $this->assertSame($key, $this->state()->get(WizardStep::Ai)['api_key']);

        // Reopening the screen shows that a key is held, and nothing more.
        Livewire::test(Ai::class)
            ->assertSet('enabled', true)
            ->assertSet('apiKey', '')
            ->assertSet('hasStoredKey', true)
            ->assertDontSee($key);

        // And leaving the field blank keeps it rather than wiping it.
        Livewire::test(Ai::class)
            ->set('model', 'gpt-4.1-mini')
            ->call('save');

        $this->assertSame($key, $this->state()->get(WizardStep::Ai)['api_key']);
    }

    #[Test]
    public function an_openai_compatible_endpoint_must_be_given_a_base_url(): void
    {
        $this->completeThrough(WizardStep::Email);

        Livewire::test(Ai::class)
            ->set('enabled', true)
            ->set('driver', 'openai_compatible')
            ->set('model', 'llama-3.1-70b')
            ->set('baseUrl', '')
            ->call('save')
            ->assertHasErrors('baseUrl')
            ->assertNoRedirect();
    }

    #[Test]
    public function changing_a_setting_after_a_failed_run_rewinds_the_steps_that_used_it(): void
    {
        $this->completeThrough(WizardStep::Ai);

        $checkpoint = $this->checkpoint();
        $checkpoint->start();
        $checkpoint->complete(InstallStep::Environment);
        $checkpoint->complete(InstallStep::EnvFile);
        $checkpoint->complete(InstallStep::Database);

        Livewire::test(Application::class)
            ->set('name', 'Renamed')
            ->call('save');

        $this->assertTrue($checkpoint->isCompleted(InstallStep::Environment));
        $this->assertFalse(
            $checkpoint->isCompleted(InstallStep::EnvFile),
            'A new application name invalidates the .env written from the old one.',
        );
        $this->assertFalse($checkpoint->isCompleted(InstallStep::Database));
    }

    /* ------------------------------------------------------------------ *
     * Harness
     * ------------------------------------------------------------------ */

    private function state(): InstallState
    {
        return $this->app->make(InstallState::class);
    }

    /**
     * Fill in the wizard up to and including `$step`, the way a person would have.
     */
    private function completeThrough(WizardStep $step): void
    {
        $answers = [
            WizardStep::Database->value => [
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'planvio',
                'username' => 'planvio',
                'password' => 'planvio',
            ],
            WizardStep::Application->value => [
                'name' => 'Acme Projects',
                'url' => 'https://acme.example.com',
                'timezone' => 'Europe/Amsterdam',
                'locale' => 'en',
                'currency' => 'EUR',
                'date_format' => 'd/m/Y',
                'environment' => 'production',
            ],
            WizardStep::Administrator->value => [
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
                'password' => 'Correct Horse Battery 7',
            ],
            WizardStep::Email->value => [
                'configured' => false,
                'from_address' => 'ada@example.com',
                'from_name' => 'Acme Projects',
            ],
            WizardStep::Ai->value => ['enabled' => false],
        ];

        $state = $this->state();

        foreach (WizardStep::sequence() as $candidate) {
            if (! $candidate->collectsData()) {
                continue;
            }

            $state->put($candidate, $answers[$candidate->value]);

            if ($candidate === $step) {
                return;
            }
        }
    }
}
