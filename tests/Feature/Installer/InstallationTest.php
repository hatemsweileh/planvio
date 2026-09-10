<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AiDriver;
use App\Enums\AiMode;
use App\Models\AiProvider;
use App\Models\AiSetting;
use App\Models\ProjectStatus;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Install\AdministratorAccount;
use App\Services\Install\AiCredentials;
use App\Services\Install\ApplicationSettings;
use App\Services\Install\DatabaseCredentials;
use App\Services\Install\DatabaseTester;
use App\Services\Install\EnvFile;
use App\Services\Install\EnvWriter;
use App\Services\Install\InstallationFailed;
use App\Services\Install\Installer;
use App\Services\Install\InstallPlan;
use App\Services\Install\InstallStep;
use App\Services\Install\MailCredentials;
use App\Support\Version;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;

/**
 * The engine, end to end, against a database that exists only for the test.
 *
 * The plan points at a SQLite file in the sandbox rather than at a MySQL server, which is why
 * {@see DatabaseCredentials} supports the driver at all: it makes a genuine installation —
 * real migrations, the real seeder, the real `CreateWorkspace` action — runnable without a
 * database server in CI.
 */
final class InstallationTest extends InstallerTestCase
{
    #[Test]
    public function a_clean_installation_finishes_every_step(): void
    {
        $installer = $this->installer();
        $plan = $this->plan();

        $this->runToCompletion($installer, $plan);

        $checkpoint = $this->checkpoint();

        $this->assertTrue($checkpoint->isFinished(), 'The checkpoint should record a finished installation.');
        $this->assertNull($checkpoint->nextStep(), 'No step should be left outstanding.');
        $this->assertNull($checkpoint->failure());

        foreach (InstallStep::sequence() as $step) {
            $this->assertTrue($checkpoint->isCompleted($step), "Step [{$step->value}] did not complete.");
        }
    }

    #[Test]
    public function it_writes_a_usable_environment_file(): void
    {
        $this->runToCompletion($this->installer(), $this->plan());

        $this->assertFileExists($this->paths->env);

        $env = EnvFile::fromFile($this->paths->env);

        $this->assertNotNull($env->get('APP_KEY'));
        $this->assertStringStartsWith('base64:', (string) $env->get('APP_KEY'));
        $this->assertSame('Acme Projects', $env->get('APP_NAME'));
        $this->assertSame('https://acme.example.com', $env->get('APP_URL'));
        $this->assertSame('production', $env->get('APP_ENV'));
        $this->assertSame('false', $env->get('APP_DEBUG'));
        $this->assertSame('true', $env->get('APP_INSTALLED'));
        $this->assertSame('database', $env->get('SESSION_DRIVER'));
        $this->assertSame('database', $env->get('QUEUE_CONNECTION'));
        $this->assertSame('Europe/Amsterdam', $env->get('APP_TIMEZONE'));
        $this->assertSame('EUR', $env->get('PLANVIO_CURRENCY'));

        // The shipped documentation survives being written through.
        $this->assertStringContainsString('# --- Database ', file_get_contents($this->paths->env));
    }

    #[Test]
    public function it_creates_the_administrator_and_the_first_workspace(): void
    {
        $this->runToCompletion($this->installer(), $this->plan());

        $administrator = User::query()->where('email', 'ada@example.com')->firstOrFail();

        $this->assertTrue($administrator->is_admin);
        $this->assertTrue($administrator->is_active);
        $this->assertNotNull($administrator->email_verified_at);
        $this->assertTrue(Hash::check('Correct Horse Battery 7', $administrator->password));
        $this->assertSame('Europe/Amsterdam', $administrator->timezone);

        $workspace = Workspace::query()->firstOrFail();

        $this->assertSame('Acme Projects', $workspace->name);
        $this->assertSame('EUR', $workspace->currency);
        $this->assertSame('d/m/Y', $workspace->date_format);
        $this->assertSame($administrator->getKey(), $workspace->owner_id);

        // The workspace comes out of CreateWorkspace, so it arrives fully furnished.
        $this->assertGreaterThan(0, ProjectStatus::withoutWorkspaceScope()->count());
        $this->assertGreaterThan(0, Tag::withoutWorkspaceScope()->count());
        $this->assertNotNull(AiSetting::query()->where('workspace_id', $workspace->getKey())->first());
    }

    #[Test]
    public function it_creates_the_storage_directories_and_the_lock(): void
    {
        $this->runToCompletion($this->installer(), $this->plan());

        $this->assertDirectoryExists($this->paths->storagePath('app/private'));
        $this->assertDirectoryExists($this->paths->storagePath('app/public'));
        $this->assertDirectoryExists($this->paths->storagePath('framework/sessions'));

        $this->assertFileExists($this->paths->lockFile);

        $lock = json_decode((string) file_get_contents($this->paths->lockFile), true);

        $this->assertIsArray($lock);
        $this->assertSame(Version::app(), $lock['version']);
    }

    #[Test]
    public function installing_with_ai_skipped_succeeds_and_leaves_ai_switched_off(): void
    {
        $this->runToCompletion($this->installer(), $this->plan());

        $this->assertSame(0, AiProvider::query()->count(), 'A skipped AI step must not create a provider.');

        $global = AiSetting::query()->whereNull('workspace_id')->firstOrFail();
        $this->assertFalse($global->is_enabled);

        $this->assertSame('false', EnvFile::fromFile($this->paths->env)->get('AI_ENABLED'));
    }

    #[Test]
    public function a_configured_ai_provider_is_stored_encrypted_and_never_written_to_the_environment_file(): void
    {
        $key = 'sk-installer-test-0123456789abcdef';

        $this->runToCompletion($this->installer(), $this->plan(ai: AiCredentials::enabled(
            driver: AiDriver::OpenAi,
            baseUrl: 'https://api.openai.com/v1',
            apiKey: $key,
            model: 'gpt-4o-mini',
            mode: AiMode::Copilot,
        )));

        $provider = AiProvider::query()->firstOrFail();

        $this->assertSame(AiDriver::OpenAi, $provider->driver);
        $this->assertSame('gpt-4o-mini', $provider->model);
        $this->assertTrue($provider->is_default);
        $this->assertSame($key, $provider->api_key, 'The provider must be able to read its own key back.');

        $stored = (string) $provider->getAttributes()['api_key'];
        $this->assertNotSame($key, $stored, 'The key must be encrypted at rest.');

        $this->assertStringNotContainsString($key, (string) file_get_contents($this->paths->env));
        $this->assertSame('true', EnvFile::fromFile($this->paths->env)->get('AI_ENABLED'));

        $global = AiSetting::query()->whereNull('workspace_id')->firstOrFail();
        $this->assertTrue($global->is_enabled);
        $this->assertSame(AiMode::Copilot, $global->default_mode);
    }

    #[Test]
    public function no_credential_is_ever_written_to_the_checkpoint_file(): void
    {
        $this->runToCompletion($this->installer(), $this->plan(ai: AiCredentials::enabled(
            driver: AiDriver::OpenAi,
            baseUrl: 'https://api.openai.com/v1',
            apiKey: 'sk-installer-test-0123456789abcdef',
            model: 'gpt-4o-mini',
            mode: AiMode::Assistant,
        )));

        $contents = (string) file_get_contents($this->paths->checkpoint);

        foreach (['Correct Horse Battery 7', 'sk-installer-test-0123456789abcdef', 'smtp-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $contents);
        }
    }

    #[Test]
    public function a_failing_step_is_recorded_safely_and_the_run_resumes_from_it(): void
    {
        $installer = $this->installer();

        $broken = $this->plan(database: DatabaseCredentials::sqlite(
            $this->sandbox.DIRECTORY_SEPARATOR.'no-such-directory'.DIRECTORY_SEPARATOR.'planvio.sqlite',
        ));

        $failure = null;

        try {
            $this->runToCompletion($installer, $broken, limit: 4);
        } catch (InstallationFailed $e) {
            $failure = $e;
        }

        $this->assertNotNull($failure, 'An unreachable database must fail the run.');
        $this->assertSame(InstallStep::Database, $failure->step);
        $this->assertNotSame('', $failure->reference);

        $recorded = $this->checkpoint()->failure();

        $this->assertIsArray($recorded);
        $this->assertSame(InstallStep::Database->value, $recorded['step']);
        $this->assertSame($failure->reference, $recorded['reference']);
        $this->assertNotSame('', $recorded['suggestion']);

        // Safe explanation only: no class names, no SQLSTATE, no file paths.
        foreach (['PDOException', 'SQLSTATE', 'Stack trace', $this->sandbox] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $recorded['reason']);
            $this->assertStringNotContainsString($forbidden, $recorded['suggestion']);
        }

        // The steps before the failure stay done; the failed one does not.
        $this->assertTrue($this->checkpoint()->isCompleted(InstallStep::EnvFile));
        $this->assertFalse($this->checkpoint()->isCompleted(InstallStep::Database));
        $this->assertSame(InstallStep::Database, $this->checkpoint()->nextStep());

        // Correct the setting and carry on — the run picks up at the step that failed.
        $this->checkpoint()->clearFailure();
        $this->runToCompletion($installer, $this->plan());

        $this->assertTrue($this->checkpoint()->isFinished());
        $this->assertNull($this->checkpoint()->failure());
        $this->assertSame(1, User::query()->count(), 'Resuming must not create a second administrator.');
        $this->assertSame(1, Workspace::query()->count(), 'Resuming must not create a second workspace.');
    }

    #[Test]
    public function running_the_engine_twice_is_harmless(): void
    {
        $installer = $this->installer();
        $plan = $this->plan();

        $this->runToCompletion($installer, $plan);
        $this->assertNull($installer->runNext($plan), 'A finished installation has nothing left to run.');

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Workspace::query()->count());
    }

    /* ------------------------------------------------------------------ *
     * Harness
     * ------------------------------------------------------------------ */

    private function installer(): Installer
    {
        return new Installer(
            app: $this->app,
            paths: $this->paths,
            checkpoint: $this->checkpoint(),
            env: new EnvWriter($this->paths),
            databaseTester: new DatabaseTester,
            createWorkspace: $this->app->make(CreateWorkspace::class),
            // config:cache would write into this repository's own bootstrap/cache and boot a
            // second application to do it. The step still runs; it just does not build one.
            cacheConfiguration: false,
        );
    }

    private function plan(
        ?DatabaseCredentials $database = null,
        ?AiCredentials $ai = null,
    ): InstallPlan {
        return new InstallPlan(
            database: $database ?? DatabaseCredentials::sqlite($this->databaseFile()),
            application: new ApplicationSettings(
                name: 'Acme Projects',
                url: 'https://acme.example.com',
                timezone: 'Europe/Amsterdam',
                locale: 'en',
                currency: 'EUR',
                dateFormat: 'd/m/Y',
                environment: ApplicationSettings::ENVIRONMENT_PRODUCTION,
            ),
            administrator: new AdministratorAccount('Ada Lovelace', 'ada@example.com', 'Correct Horse Battery 7'),
            mail: MailCredentials::smtp(
                host: 'mail.example.com',
                port: 587,
                encryption: MailCredentials::ENCRYPTION_TLS,
                username: 'planvio@example.com',
                password: 'smtp-secret',
                fromAddress: 'planvio@example.com',
                fromName: 'Acme Projects',
            ),
            ai: $ai ?? AiCredentials::disabled(),
        );
    }

    private function databaseFile(): string
    {
        $path = $this->sandbox.DIRECTORY_SEPARATOR.'planvio.sqlite';

        if (! is_file($path)) {
            touch($path);
        }

        return $path;
    }

    /**
     * Drive the engine the way the progress screen does: one step per call.
     */
    private function runToCompletion(Installer $installer, InstallPlan $plan, int $limit = 40): void
    {
        for ($i = 0; $i < $limit; $i++) {
            if ($installer->runNext($plan) === null) {
                return;
            }
        }

        $this->fail('The installer did not finish within '.$limit.' steps.');
    }
}
