<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Models\Locale;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Renders the real application in Arabic and writes the HTML to public/ so a human can
 * look at it in a browser.
 *
 * Not part of the normal suite — it is tagged `visual` and excluded by phpunit.xml, because
 * it writes files and asserts almost nothing. Run it deliberately:
 *
 *     ./php artisan test --without-tty --filter=RenderArabicForReview
 *
 * It exists because RTL correctness is not a property you can assert. Overlapping controls,
 * a sidebar on the wrong edge, an English title truncated from its beginning — all of that
 * is visible in a second and invisible to every assertion worth writing.
 */
#[Group('visual')]
final class RenderArabicForReviewTest extends TestCase
{
    #[Test]
    public function it_writes_arabic_and_english_renders_for_review(): void
    {
        $this->markTestSkippedUnlessSeeded();

        $admin = User::query()->where('email', 'admin@planvio.test')->firstOrFail();
        // Workspace is the tenant, not a tenant-scoped record, so it has no scope to lift.
        $workspace = Workspace::query()->firstOrFail();
        $project = Project::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->firstOrFail();

        $pages = [
            'home' => "/w/{$workspace->slug}",
            'projects' => "/w/{$workspace->slug}/projects",
            'board' => "/w/{$workspace->slug}/projects/{$project->slug}/board",
            'tasks' => "/w/{$workspace->slug}/projects/{$project->slug}/tasks",
            'my-tasks' => "/w/{$workspace->slug}/my-tasks",
            'settings' => "/w/{$workspace->slug}/settings",
        ];

        foreach (['ar', 'en'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();

            foreach ($pages as $name => $path) {
                $response = $this->actingAs($admin->fresh())->get($path);

                $this->assertSame(
                    200,
                    $response->getStatusCode(),
                    "{$path} returned {$response->getStatusCode()} in {$locale}.",
                );

                file_put_contents(
                    public_path("_render-{$locale}-{$name}.html"),
                    $response->getContent(),
                );
            }
        }

        $admin->forceFill(['locale' => 'ar'])->save();
    }

    private function markTestSkippedUnlessSeeded(): void
    {
        /*
         * This test reads the demo workspace rather than building one, because what is under
         * review is how a realistic amount of content lays out, not whether a fixture renders.
         *
         * The whole check sits inside a try: under phpunit.xml the connection is an empty
         * :memory: database with no tables at all, so asking whether a locale exists throws
         * rather than answering false. Either way the answer is the same — there is nothing
         * here to look at — and a visual aid must never fail the suite.
         */
        try {
            $ready = Locale::query()->where('code', 'ar')->exists()
                && User::query()->where('email', 'admin@planvio.test')->exists();
        } catch (Throwable) {
            $ready = false;
        }

        if (! $ready) {
            $this->markTestSkipped(
                'Needs a seeded database. Run: ./php artisan migrate:fresh --seed --force '
                .'&& ./php artisan planvio:demo --force, then re-run with '
                .'DB_DATABASE pointing at database/database.sqlite.',
            );
        }
    }
}
