<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use App\Services\Install\EnvFile;
use App\Services\Install\InstallerEnvironment;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\Test;

/**
 * What has to be true before an unconfigured Planvio can render anything at all.
 *
 * The release ZIP ships no `.env`, so the first request a customer ever makes arrives at an
 * application with no `APP_KEY` and a session driver pointed at a database that does not
 * exist. Everything asserted here is what turns that into a page.
 */
final class InstallerEnvironmentTest extends InstallerTestCase
{
    #[Test]
    public function the_wizard_runs_on_drivers_that_need_no_database(): void
    {
        $config = new Repository([
            'session' => ['driver' => 'database', 'encrypt' => false, 'cookie' => 'acme_projects_session', 'secure' => true],
            'cache' => ['default' => 'database'],
            'queue' => ['default' => 'database'],
        ]);

        InstallerEnvironment::configureForInstallation($config);

        $this->assertSame('file', $config->get('session.driver'));
        $this->assertSame('file', $config->get('cache.default'));
        $this->assertSame('sync', $config->get('queue.default'));
    }

    #[Test]
    public function the_session_holding_four_passwords_is_encrypted_at_rest(): void
    {
        $config = new Repository(['session' => ['encrypt' => false]]);

        InstallerEnvironment::configureForInstallation($config);

        $this->assertTrue($config->get('session.encrypt'));
    }

    #[Test]
    public function the_session_cookie_keeps_one_name_for_the_whole_installation(): void
    {
        // The default name is derived from APP_NAME, and the wizard rewrites APP_NAME in the
        // middle of the run. Without a fixed name the request after `.env` is written looks
        // for a cookie that was never set, starts an empty session, loses the plan and
        // answers 419 to its own progress poll.
        $config = new Repository(['session' => ['cookie' => 'planvio_session']]);

        InstallerEnvironment::configureForInstallation($config);

        $before = $config->get('session.cookie');

        $config->set('app.name', 'Acme Projects');
        InstallerEnvironment::configureForInstallation($config);

        $this->assertSame($before, $config->get('session.cookie'));
    }

    #[Test]
    public function the_session_cookie_is_not_marked_secure_while_installing(): void
    {
        // The address the administrator types is where Planvio *will* live, and it is very
        // often https on a certificate that has not been issued yet. A secure cookie on the
        // plain-HTTP connection they are installing over is simply discarded.
        $config = new Repository(['session' => ['secure' => true]]);

        InstallerEnvironment::configureForInstallation($config);

        $this->assertFalse($config->get('session.secure'));
    }

    #[Test]
    public function the_release_ships_a_template_and_no_environment_file(): void
    {
        // scripts/build-release.php refuses to package a `.env`, so the installer is written
        // for a tree that has only the template — including the very first request, which has
        // no application key to encrypt a cookie with.
        $this->assertFileExists($this->paths->envExample());
        $this->assertFileDoesNotExist($this->paths->env);

        $template = EnvFile::fromFile($this->paths->envExample());

        $this->assertNull($template->get('APP_KEY'), '.env.example must ship without a key.');
        $this->assertSame('false', $template->get('APP_INSTALLED'));
    }
}
