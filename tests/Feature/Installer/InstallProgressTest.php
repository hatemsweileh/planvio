<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use App\Actions\Workspaces\CreateWorkspace;
use App\Livewire\Installer\Install;
use App\Services\Install\DatabaseTester;
use App\Services\Install\EnvWriter;
use App\Services\Install\Installer;
use App\Services\Install\InstallState;
use App\Services\Install\InstallStep;
use App\Services\Install\WizardStep;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The progress screen, and what it does when a step will not complete (spec §139, §140).
 *
 * The plan here is complete but names no database, so the run reliably reaches the database
 * step and stops there — which is the state worth testing. The happy path is covered against a
 * real database by {@see InstallationTest}.
 */
final class InstallProgressTest extends InstallerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Resolved rather than newed by the component, so the sandboxed paths and the
        // "do not write a configuration cache" decision both reach it.
        $this->app->bind(Installer::class, fn ($app): Installer => new Installer(
            app: $app,
            paths: $this->paths,
            checkpoint: $this->checkpoint(),
            env: new EnvWriter($this->paths),
            databaseTester: new DatabaseTester,
            createWorkspace: $app->make(CreateWorkspace::class),
            cacheConfiguration: false,
        ));
    }

    #[Test]
    public function it_refuses_to_start_without_a_complete_plan(): void
    {
        Livewire::test(Install::class)->assertRedirect(route('install.database'));
    }

    #[Test]
    public function each_poll_runs_exactly_one_step(): void
    {
        $this->completePlan();

        $component = Livewire::test(Install::class)
            ->assertSet('completed', [])
            ->assertSet('current', InstallStep::Environment->value);

        $component->call('tick')
            ->assertSet('completed', [InstallStep::Environment->value])
            ->assertSet('current', InstallStep::EnvFile->value);

        $component->call('tick')
            ->assertSet('completed', [InstallStep::Environment->value, InstallStep::EnvFile->value])
            ->assertSet('current', InstallStep::Database->value);

        // The configuration file is on disk after its own step and not before.
        $this->assertFileExists($this->paths->env);
    }

    #[Test]
    public function a_failure_stops_the_run_and_explains_itself(): void
    {
        $this->completePlan();

        $component = Livewire::test(Install::class);

        for ($i = 0; $i < 3; $i++) {
            $component->call('tick');
        }

        $component->assertSet('failure.step', InstallStep::Database->value)
            ->assertSee('Installation could not be completed.')
            ->assertSee('Suggested action')
            ->assertSee('Log reference');

        $failure = $component->get('failure');

        $this->assertNotSame('', $failure['reason']);
        $this->assertNotSame('', $failure['suggestion']);
        $this->assertNotSame('', $failure['reference']);

        // Nothing technical, and nothing that could carry a credential.
        foreach (['PDOException', 'SQLSTATE', 'planvio-secret', $this->sandbox] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $failure['reason']);
            $this->assertStringNotContainsString($forbidden, $failure['suggestion']);
        }

        // The lock is not written, so the wizard is still reachable — no permanent lockout.
        $this->assertFileDoesNotExist($this->paths->lockFile);

        $this->restoreTestConnection();
        $this->get(route('install.database'))->assertOk();
    }

    #[Test]
    public function a_failed_run_polls_no_further_until_it_is_retried(): void
    {
        $this->completePlan();

        $component = Livewire::test(Install::class);

        for ($i = 0; $i < 3; $i++) {
            $component->call('tick');
        }

        $component->assertSet('failure.step', InstallStep::Database->value);

        // isRunning() is what the poll attribute is bound to; a stopped run stops polling.
        $this->assertFalse($component->instance()->isRunning());

        $component->call('tick')->assertSet('failure.step', InstallStep::Database->value);
    }

    #[Test]
    public function retrying_resumes_at_the_failed_step_rather_than_the_first_one(): void
    {
        $this->completePlan();

        $component = Livewire::test(Install::class);

        for ($i = 0; $i < 3; $i++) {
            $component->call('tick');
        }

        $component->call('retry')
            ->assertSet('failure', [])
            ->assertSet('current', InstallStep::Database->value)
            ->assertSet('completed', [InstallStep::Environment->value, InstallStep::EnvFile->value]);

        $this->assertNull($this->checkpoint()->failure());
    }

    #[Test]
    public function change_my_settings_lands_on_the_screen_that_owns_the_failure(): void
    {
        $this->completePlan();

        $component = Livewire::test(Install::class);

        for ($i = 0; $i < 3; $i++) {
            $component->call('tick');
        }

        $component->call('reconfigure')->assertRedirect(route('install.database'));
    }

    #[Test]
    public function the_screen_is_reachable_again_after_a_failure_so_nobody_is_locked_out(): void
    {
        $this->completePlan();

        $component = Livewire::test(Install::class);

        for ($i = 0; $i < 3; $i++) {
            $component->call('tick');
        }

        $this->restoreTestConnection();

        // Every earlier screen opens again, with its answers still in place.
        foreach ([WizardStep::Database, WizardStep::Application, WizardStep::Administrator, WizardStep::Email, WizardStep::Ai] as $step) {
            $this->get($step->url())->assertOk();
        }
    }

    /**
     * Put the suite's own connection back after a run has repointed it.
     *
     * The engine sets `database.default` to whatever the plan names — that is its job — and
     * this test names one that does not work. Restoring it keeps the HTTP assertions below
     * about the wizard rather than about how long a dead connection takes to give up.
     */
    private function restoreTestConnection(): void
    {
        config(['database.default' => 'sqlite']);
    }

    /**
     * Every screen answered, but with no database name — so the run reaches the database step
     * and stops there without opening a socket. Which connection failures are reported, and
     * how, is {@see DatabaseStepTest}'s job; this file is about what the progress screen does
     * once one has happened.
     */
    private function completePlan(): void
    {
        $state = $this->app->make(InstallState::class);

        $state->put(WizardStep::Database, [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => '',
            'username' => 'planvio',
            'password' => 'planvio-secret',
        ]);

        $state->put(WizardStep::Application, [
            'name' => 'Acme Projects',
            'url' => 'https://acme.example.com',
            'timezone' => 'Europe/Amsterdam',
            'locale' => 'en',
            'currency' => 'EUR',
            'date_format' => 'd/m/Y',
            'environment' => 'production',
        ]);

        $state->put(WizardStep::Administrator, [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Correct Horse Battery 7',
        ]);

        $state->put(WizardStep::Email, [
            'configured' => false,
            'from_address' => 'ada@example.com',
            'from_name' => 'Acme Projects',
        ]);

        $state->put(WizardStep::Ai, ['enabled' => false]);
    }
}
