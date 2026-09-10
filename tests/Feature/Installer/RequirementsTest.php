<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use App\Services\Install\Requirement;
use App\Services\Install\RequirementChecker;
use App\Services\Install\RequirementStatus;
use App\Services\Install\WizardStep;
use PHPUnit\Framework\Attributes\Test;

/**
 * The pre-flight gate (spec §67).
 *
 * The important assertion here is not that the screen renders. It is that a mandatory failure
 * stops the installation *on the server*: the button is disabled for clarity, but the POST is
 * what actually decides, and a browser is not where that decision belongs.
 */
final class RequirementsTest extends InstallerTestCase
{
    #[Test]
    public function it_reports_php_extensions_and_folders(): void
    {
        $this->get(route('install.requirements'))
            ->assertOk()
            ->assertSee('PHP extensions')
            ->assertSee('Folders and files')
            ->assertSee('Configuration file (.env)')
            ->assertSee('mbstring');
    }

    #[Test]
    public function a_missing_mandatory_extension_blocks_the_wizard(): void
    {
        config(['planvio.install.required_extensions' => ['mbstring', 'planvio_not_a_real_extension']]);

        $this->get(route('install.requirements'))
            ->assertOk()
            ->assertSee('planvio_not_a_real_extension')
            ->assertSee('Select PHP Version', false);

        $this->post(route('install.requirements.continue'))
            ->assertRedirect(route('install.requirements'))
            ->assertSessionHas('installer.error');

        // And the step it guards stays out of reach.
        $this->get(route('install.database'))->assertOk();
    }

    #[Test]
    public function an_unwritable_path_blocks_the_wizard_and_explains_the_fix(): void
    {
        config(['planvio.install.writable_paths' => ['storage/app', 'storage/definitely-not-here']]);

        $response = $this->get(route('install.requirements'));

        $response->assertOk()
            ->assertSee('storage/definitely-not-here/')
            ->assertSee('Directory is missing')
            ->assertSee('Change Permissions');

        $this->post(route('install.requirements.continue'))
            ->assertRedirect(route('install.requirements'));
    }

    #[Test]
    public function an_optional_extension_only_warns(): void
    {
        config([
            'planvio.install.required_extensions' => ['mbstring'],
            'planvio.install.optional_extensions' => ['planvio_optional_extension'],
        ]);

        $report = (new RequirementChecker($this->paths))->check();

        $this->assertTrue($report->passes(), 'An optional extension must never block the installation.');
        $this->assertNotSame([], $report->warnings());

        $this->post(route('install.requirements.continue'))
            ->assertRedirect(route(WizardStep::Database->routeName()));
    }

    #[Test]
    public function it_answers_the_env_question_in_whichever_state_the_tree_is_in(): void
    {
        $checker = new RequirementChecker($this->paths);

        $creatable = $this->requirement($checker, 'env_writable');
        $this->assertSame(RequirementStatus::Pass, $creatable->status);
        $this->assertSame('Can be created', $creatable->detail);

        file_put_contents($this->paths->env, "APP_KEY=\n");

        $present = $this->requirement(new RequirementChecker($this->paths), 'env_writable');
        $this->assertSame(RequirementStatus::Pass, $present->status);
        $this->assertSame('Present and writable', $present->detail);
    }

    #[Test]
    public function every_failing_check_carries_an_instruction(): void
    {
        config([
            'planvio.install.required_extensions' => ['planvio_not_a_real_extension'],
            'planvio.install.writable_paths' => ['storage/definitely-not-here'],
        ]);

        $report = (new RequirementChecker($this->paths))->check();

        $this->assertNotSame([], $report->blocking());

        foreach ($report->blocking() as $requirement) {
            $this->assertNotNull(
                $requirement->remedy,
                "The failing check [{$requirement->key}] tells nobody how to fix it.",
            );
        }
    }

    private function requirement(RequirementChecker $checker, string $key): Requirement
    {
        foreach ($checker->check()->requirements as $requirement) {
            if ($requirement->key === $key) {
                return $requirement;
            }
        }

        $this->fail("No requirement named [{$key}].");
    }
}
