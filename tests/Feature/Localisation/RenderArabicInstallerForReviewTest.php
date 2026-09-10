<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Enums\AiDriver;
use App\Services\Install\InstallCheckpoint;
use App\Services\Install\InstallerEnvironment;
use App\Services\Install\InstallPaths;
use App\Services\Install\WizardStep;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Installer\InstallerTestCase;
use Tests\TestCase;

/**
 * The installation wizard, rendered in both languages so somebody can look at it.
 *
 *     ./php artisan test --without-tty --filter=RenderArabicInstallerForReview
 *
 * The wizard only exists while the application considers itself uninstalled, so this test
 * points the lock file at a directory that does not exist and clears `APP_INSTALLED` for the
 * duration — the same sandboxing {@see InstallerTestCase} does, kept
 * local here because this file writes review output rather than asserting behaviour.
 */
#[Group('visual')]
final class RenderArabicInstallerForReviewTest extends TestCase
{
    private string $sandbox;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    #[Test]
    public function it_writes_every_wizard_step_in_both_languages(): void
    {
        $this->sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'planvio-visual-'.bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0777, true);

        $this->setEnvironmentValue('APP_INSTALLED', 'false');

        $paths = new InstallPaths(
            base: $this->sandbox,
            env: $this->sandbox.DIRECTORY_SEPARATOR.'.env',
            public: $this->sandbox.DIRECTORY_SEPARATOR.'public',
            storage: $this->sandbox.DIRECTORY_SEPARATOR.'storage',
            lockFile: $this->sandbox.DIRECTORY_SEPARATOR.'planvio-installed.lock',
            checkpoint: $this->sandbox.DIRECTORY_SEPARATOR.'planvio-install-progress.json',
        );

        /*
         | A brand-new server has no tables, which is the condition the wizard actually runs
         | under: Locale::enabledByCode() answers with an empty collection and SetLocale has
         | nothing to resolve. Pointing at an empty in-memory database reproduces that even
         | when the suite is run against a seeded file.
         */
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');

        config(['planvio.install.lock_file' => $paths->lockFile]);
        $this->app->instance(InstallPaths::class, $paths);
        $this->app->instance(InstallCheckpoint::class, new InstallCheckpoint($paths->checkpoint));

        try {
            foreach (['ar', 'en'] as $locale) {
                config(['app.locale' => $locale]);

                foreach ($this->steps() as $name => $path) {
                    $response = $this->withSession($this->wizardSession())->get($path);

                    $this->dump($locale, $name, $response->getContent(), $response->getStatusCode());
                }
            }
        } finally {
            $this->restoreEnvironment();
            $this->deleteDirectory($this->sandbox);
        }
    }

    /**
     * @return array<string, string>
     */
    private function steps(): array
    {
        return [
            'install-welcome' => '/install',
            'install-requirements' => '/install/requirements',
            'install-database' => '/install/database',
            'install-application' => '/install/application',
            'install-administrator' => '/install/administrator',
            'install-email' => '/install/email',
            'install-ai' => '/install/ai',
            'install-run' => '/install/run',
        ];
    }

    /**
     * Enough completed steps that every later screen is reachable.
     *
     * @return array<string, mixed>
     */
    private function wizardSession(): array
    {
        $key = 'planvio.installer.';

        return [
            $key.WizardStep::Database->value => [
                'host' => '127.0.0.1', 'port' => 3306, 'database' => 'planvio',
                'username' => 'planvio', 'password' => 'planvio', 'tested' => true,
            ],
            $key.WizardStep::Application->value => [
                'name' => 'Planvio', 'url' => 'https://planvio.example', 'timezone' => 'UTC',
                'locale' => 'ar', 'currency' => 'USD', 'date_format' => 'Y-m-d',
                'environment' => 'production',
            ],
            $key.WizardStep::Administrator->value => [
                'name' => 'Nadia Haddad', 'email' => 'nadia@example.com', 'password' => 'S3cret-passphrase!',
            ],
            $key.WizardStep::Email->value => [
                'configured' => false, 'from_address' => 'nadia@example.com', 'from_name' => 'Planvio',
            ],
            $key.WizardStep::Ai->value => [
                'configured' => true, 'driver' => AiDriver::OpenAi->value,
                'model' => 'gpt-4o-mini', 'api_key' => 'sk-not-a-real-key', 'base_url' => '',
            ],
        ];
    }

    private function dump(string $locale, string $name, ?string $html, int $status): void
    {
        $html = str_replace([rtrim((string) config('app.url'), '/').'/', 'http://localhost/'], '/', (string) $html);

        file_put_contents(
            public_path("_perimeter-{$locale}-{$name}.html"),
            "<!-- HTTP {$status} -->\n".$html,
        );
    }

    private function setEnvironmentValue(string $key, string $value): void
    {
        if (! array_key_exists($key, $this->originalEnv)) {
            $this->originalEnv[$key] = getenv($key);
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function restoreEnvironment(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $this->originalEnv = [];

        InstallerEnvironment::flush();
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$entry;

            is_dir($child) && ! is_link($child) ? $this->deleteDirectory($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
