<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use App\Livewire\Installer\Database;
use App\Services\Install\DatabaseCredentials;
use App\Services\Install\DatabaseTester;
use App\Services\Install\InstallState;
use App\Services\Install\RequirementStatus;
use App\Services\Install\WizardStep;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * "Test connection" (spec §68).
 *
 * A wrong password is a typo, not a fault: it must produce a sentence in the panel, not a
 * 500. And it must produce a sentence somebody can act on — never a driver message, which is
 * written for a developer reading a terminal and routinely quotes the DSN it just failed to
 * open.
 */
final class DatabaseStepTest extends InstallerTestCase
{
    #[Test]
    public function an_unreachable_server_is_reported_rather_than_thrown(): void
    {
        // Port 1 is reserved and never listening, so this is a connection failure wherever
        // the suite runs — no database server required, and no dependence on one being absent.
        $result = (new DatabaseTester)->test(DatabaseCredentials::mysql(
            host: '127.0.0.1',
            port: 1,
            database: 'planvio_installer_test',
            username: 'planvio',
            password: 'wrong',
        ));

        $this->assertFalse($result->ok());
        $this->assertSame(RequirementStatus::Fail, $result->status);
        $this->assertNotSame('', $result->message);

        foreach (['PDOException', 'SQLSTATE', 'Stack trace', '#0 ', 'wrong'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $result->message);
            $this->assertStringNotContainsString($forbidden, (string) $result->detail);
        }
    }

    #[Test]
    public function a_missing_database_name_is_caught_before_a_connection_is_attempted(): void
    {
        $result = (new DatabaseTester)->test(DatabaseCredentials::mysql('127.0.0.1', 3306, '', 'planvio', ''));

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('name of the database', $result->message);
    }

    #[Test]
    public function a_working_connection_passes_and_names_where_it_connected(): void
    {
        $file = $this->sandbox.DIRECTORY_SEPARATOR.'probe.sqlite';
        touch($file);

        $result = (new DatabaseTester)->test(DatabaseCredentials::sqlite($file));

        $this->assertTrue($result->ok());
        $this->assertSame(RequirementStatus::Pass, $result->status);
        $this->assertStringContainsString('probe.sqlite', $result->message);
    }

    #[Test]
    public function a_database_that_already_has_tables_warns_without_blocking(): void
    {
        $file = $this->sandbox.DIRECTORY_SEPARATOR.'occupied.sqlite';
        $pdo = new \PDO('sqlite:'.$file);
        $pdo->exec('CREATE TABLE something_else (id integer primary key)');

        $result = (new DatabaseTester)->test(DatabaseCredentials::sqlite($file));

        $this->assertTrue($result->ok(), 'An occupied database is a warning, not a refusal.');
        $this->assertSame(RequirementStatus::Warn, $result->status);
        $this->assertStringContainsString('already contains', (string) $result->detail);
    }

    #[Test]
    public function the_screen_shows_the_failure_and_refuses_to_move_on(): void
    {
        Livewire::test(Database::class)
            ->set('host', '127.0.0.1')
            ->set('port', 1)
            ->set('database', 'planvio_installer_test')
            ->set('username', 'planvio')
            ->set('password', 'wrong')
            ->call('save')
            ->assertNoRedirect()
            ->assertSet('result.status', RequirementStatus::Fail->value);

        $this->assertFalse(
            $this->app->make(InstallState::class)->has(WizardStep::Database),
            'Credentials that do not work must not be stored.',
        );
    }

    #[Test]
    public function the_screen_validates_before_it_dials(): void
    {
        Livewire::test(Database::class)
            ->set('database', '')
            ->set('username', '')
            ->call('test')
            ->assertHasErrors(['database' => 'required', 'username' => 'required'])
            ->assertSet('result', []);
    }

    #[Test]
    public function editing_a_field_discards_the_previous_result(): void
    {
        $component = Livewire::test(Database::class)
            ->set('database', 'planvio_installer_test')
            ->set('username', 'planvio')
            ->set('port', 1)
            ->call('test');

        $component->assertSet('result.status', RequirementStatus::Fail->value);

        $component->set('password', 'another-guess')
            ->assertSet('result', []);
    }
}
