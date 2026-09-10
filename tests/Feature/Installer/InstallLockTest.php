<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use App\Services\Install\InstallStep;
use App\Services\Install\WizardStep;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * The lock (spec §74).
 *
 * The wizard writes `.env`, creates a platform administrator and points the application at a
 * database. Leaving it reachable afterwards would hand any visitor a way to repoint a live
 * installation at a database of their own, so the check is made on the server for every
 * installer route — and the only way back is two deliberate acts on the filesystem, neither
 * of which has a URL.
 */
final class InstallLockTest extends InstallerTestCase
{
    #[Test]
    public function the_wizard_answers_while_the_application_is_unconfigured(): void
    {
        $this->get('/install')
            ->assertOk()
            ->assertSee('Start installation');
    }

    #[Test]
    public function the_lock_file_closes_every_installer_screen(): void
    {
        $this->writeLockFile();

        foreach ([WizardStep::Welcome, WizardStep::Requirements, WizardStep::Database, WizardStep::Application, WizardStep::Administrator, WizardStep::Email, WizardStep::Ai, WizardStep::Install] as $step) {
            $this->get($step->url())
                ->assertRedirect('/')
                ->assertSessionMissing('installer');
        }
    }

    #[Test]
    public function the_lock_file_closes_the_requirements_form_too(): void
    {
        $this->writeLockFile();

        // A POST is the one that would actually do something, so it gets its own assertion
        // rather than relying on the GET being guarded.
        $this->post(route('install.requirements.continue'))->assertRedirect('/');
    }

    #[Test]
    public function the_lock_is_checked_on_the_server_and_not_by_hiding_the_link(): void
    {
        $this->writeLockFile();

        // Nothing is being clicked here: the address is typed, exactly as an attacker would.
        $this->get('/install/run')->assertRedirect('/');

        // And the gate is the file, not a session flag or a cached decision.
        unlink($this->paths->lockFile);

        $this->get('/install')->assertOk();
    }

    #[Test]
    public function starting_over_clears_the_progress_and_never_the_lock(): void
    {
        $this->checkpoint()->start();
        $this->checkpoint()->complete(InstallStep::Environment);

        $this->get('/install')->assertOk()->assertSee('Resume installation');

        $this->post(route('install.restart'))
            ->assertRedirect(route('install.requirements'));

        $this->assertFalse($this->checkpoint()->hasStarted());
        $this->assertFileDoesNotExist($this->paths->checkpoint);

        $this->get('/install')->assertOk()->assertDontSee('Resume installation');
    }

    #[Test]
    public function starting_over_is_refused_once_the_installation_exists(): void
    {
        $this->checkpoint()->start();
        $this->writeLockFile();

        $this->post(route('install.restart'))->assertRedirect('/');

        // The one thing it must never be is a way to un-install: the progress file is
        // untouched and so, obviously, is the lock.
        $this->assertTrue($this->checkpoint()->hasStarted());
        $this->assertFileExists($this->paths->lockFile);
    }

    #[Test]
    public function no_route_can_remove_the_lock(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName() ?? '';

            if (! str_starts_with($name, 'install.')) {
                continue;
            }

            // Resetting an installation is deliberately manual: delete the lock file *and*
            // drop the tables. A DELETE or a "reset" endpoint would collapse both into one
            // unauthenticated request.
            if (array_intersect($route->methods(), ['DELETE', 'PUT', 'PATCH']) !== []) {
                $offenders[] = $name;
            }

            if (str_contains($name, 'reset') || str_contains($route->uri(), 'reset')) {
                $offenders[] = $name;
            }
        }

        $this->assertSame([], $offenders, 'The installer must expose no route that undoes an installation.');
    }

    #[Test]
    public function the_finish_screen_is_the_only_installer_route_that_survives_the_lock(): void
    {
        // Before the installation completes it says nothing and sends people to the start.
        $this->get(route('install.finish'))->assertRedirect(route('install.welcome'));

        $this->checkpoint()->start();
        $this->checkpoint()->finish('1.0.0');
        $this->writeLockFile();

        $this->get(route('install.finish'))
            ->assertOk()
            ->assertSee('Planvio is installed')
            ->assertSee('Delete the lock file')
            ->assertSee('Drop every table in the database');
    }
}
