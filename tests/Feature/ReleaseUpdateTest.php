<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ReleaseUpdate;
use App\Support\Settings;
use App\Support\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An uploaded 1.2.0 adopts itself on an installation that is still recorded as 1.0.0.
 *
 * The files live in a temporary directory. The boot hook is covered separately, and it
 * must not run against this working tree: the suite sets APP_INSTALLED, so a hook that
 * ignored that would delete compiled caches out from under the process running it.
 */
final class ReleaseUpdateTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/planvio-release-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    #[Test]
    public function the_shipped_versions_agree(): void
    {
        $this->assertSame('1.2.0', Version::app());
        $this->assertSame('1.0.0', Version::db());
        $this->assertSame(Version::app(), config('planvio.version'));
        $this->assertSame(Version::db(), config('planvio.db_version'));
    }

    #[Test]
    public function the_test_suite_does_not_apply_an_update_to_the_working_tree(): void
    {
        $planted = base_path('bootstrap/cache/planvio-update-test.php');
        file_put_contents($planted, "<?php return ['version' => '1.0.0'];".PHP_EOL);

        try {
            $this->settings()->set('app_version', '1.0.0');

            $this->assertFalse(ReleaseUpdate::shouldRun(runningUnitTests: true, installed: true));
            $this->assertFalse(ReleaseUpdate::applyIfNeeded());
            $this->assertFileExists($planted);
            $this->assertSame('1.0.0', $this->settings()->get('app_version'));
        } finally {
            @unlink($planted);
        }
    }

    #[Test]
    public function an_older_installation_adopts_the_shipped_release_without_touching_its_data(): void
    {
        $this->settings()->set('app_version', '1.0.0');
        $this->settings()->set('db_version', '0.0.1');

        $this->plant('bootstrap/cache/config.php', "<?php return ['version' => '1.0.0'];".PHP_EOL);
        $this->plant('bootstrap/cache/.gitignore', "*\n!.gitignore\n");
        $this->plant('storage/framework/views/compiled.php', '<?php echo "old";'.PHP_EOL);
        $this->plant('storage/framework/views/.gitignore', "*\n!.gitignore\n");
        $this->plant('storage/framework/cache/data/aa/payload.php', '<?php return 1;'.PHP_EOL);
        $this->plant('storage/app/private/attachment.txt', 'keep');
        $this->plant('resources/views/welcome.blade.php', 'old welcome');
        $this->plant('.env', "APP_KEY=base64:test\nAPP_INSTALLED=true\n");

        $outside = dirname($this->root).'/planvio-outside-'.bin2hex(random_bytes(4)).'.txt';
        file_put_contents($outside, 'secret');
        symlink($outside, $this->root.'/bootstrap/cache/linked.php');

        $lock = $this->root.'/storage/app/planvio-installed.lock';
        $this->plant('storage/app/planvio-installed.lock', json_encode([
            'version' => '1.0.0',
            'db_version' => '1.0.0',
            'installed_at' => '2026-09-09T00:00:00+00:00',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        try {
            $this->assertTrue($this->updater($lock)->apply());

            $this->assertSame('1.2.0', $this->settings()->get('app_version'));
            $this->assertSame('0.0.1', $this->settings()->get('db_version'));

            $this->assertFileDoesNotExist($this->root.'/bootstrap/cache/config.php');
            $this->assertFileDoesNotExist($this->root.'/storage/framework/views/compiled.php');
            $this->assertFileDoesNotExist($this->root.'/storage/framework/cache/data/aa/payload.php');
            $this->assertFileDoesNotExist($this->root.'/resources/views/welcome.blade.php');
            $this->assertFileExists($this->root.'/bootstrap/cache/.gitignore');
            $this->assertFileExists($this->root.'/storage/framework/views/.gitignore');
            $this->assertSame('keep', file_get_contents($this->root.'/storage/app/private/attachment.txt'));
            $this->assertSame("APP_KEY=base64:test\nAPP_INSTALLED=true\n", file_get_contents($this->root.'/.env'));
            $this->assertSame('secret', file_get_contents($outside));
            $this->assertFileExists($this->root.'/bootstrap/cache/linked.php');

            $recorded = json_decode((string) file_get_contents($lock), true);
            $this->assertSame('1.2.0', $recorded['version']);
            $this->assertSame('1.0.0', $recorded['db_version']);
            $this->assertSame('2026-09-09T00:00:00+00:00', $recorded['installed_at']);

            $this->assertFalse($this->updater($lock)->apply());
        } finally {
            @unlink($outside);
        }
    }

    #[Test]
    public function a_current_or_newer_release_is_left_alone(): void
    {
        $this->settings()->set('app_version', Version::app());
        $this->plant('bootstrap/cache/config.php', '<?php return [];'.PHP_EOL);

        $this->assertFalse($this->updater(null)->apply());
        $this->assertFileExists($this->root.'/bootstrap/cache/config.php');

        $this->settings()->set('app_version', '9.9.9');
        $this->assertFalse($this->updater(null)->apply());
        $this->assertSame('9.9.9', $this->settings()->get('app_version'));
        $this->assertFileExists($this->root.'/bootstrap/cache/config.php');
    }

    #[Test]
    public function a_non_version_value_is_not_treated_as_an_older_release(): void
    {
        $this->settings()->set('app_version', 'custom');
        $this->plant('bootstrap/cache/config.php', '<?php return [];'.PHP_EOL);

        $this->assertFalse($this->updater(null)->apply());
        $this->assertSame('custom', $this->settings()->get('app_version'));
        $this->assertFileExists($this->root.'/bootstrap/cache/config.php');
    }

    #[Test]
    public function a_missing_application_version_is_recorded(): void
    {
        $this->assertNull($this->settings()->get('app_version'));

        $this->assertTrue($this->updater(null)->apply());
        $this->assertSame(Version::app(), $this->settings()->get('app_version'));
    }

    private function updater(?string $lock): ReleaseUpdate
    {
        return new ReleaseUpdate($this->settings(), $this->root, $lock);
    }

    private function settings(): Settings
    {
        return $this->app->make(Settings::class);
    }

    private function plant(string $relative, string $contents): void
    {
        $path = $this->root.'/'.$relative;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents);
    }
}
