<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Enums\AiMessageRole;
use App\Enums\AiToolRisk;
use App\Livewire\App\Ai\Index as AiIndex;
use App\Livewire\App\Projects\Ai as ProjectAiComponent;
use App\Livewire\App\Projects\WikiPage as WikiPageComponent;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiRun;
use App\Models\AiToolRun;
use App\Models\Locale;
use App\Models\Project;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Workspace;
use Illuminate\Foundation\Vite;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * The companion of {@see RenderArabicForReviewTest} for the AI, settings, teams, profile,
 * reports and wiki surfaces.
 *
 * Same contract: tagged `visual`, excluded from the suite, writes HTML into `public/` so a
 * person can open it in a browser and look. It is separate from the other file only because
 * these pages need a seeded AI conversation, an approval waiting on somebody and a run
 * history, none of which the demo command writes.
 *
 *     ./php artisan test --without-tty --filter=RenderAiAndSettingsForReview
 *
 * Absolute asset URLs are rewritten to root-relative on the way out, so a dump opened from
 * any origin still finds the stylesheet it was rendered against.
 */
#[Group('visual')]
final class RenderAiAndSettingsForReviewTest extends TestCase
{
    #[Test]
    public function it_writes_arabic_and_english_renders_for_review(): void
    {
        $this->markTestSkippedUnlessSeeded();

        $admin = User::query()->where('email', 'admin@planvio.test')->firstOrFail();
        $workspace = Workspace::query()->firstOrFail();
        $project = Project::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->firstOrFail();
        $wikiPage = WikiPage::withoutWorkspaceScope()->where('project_id', $project->id)->firstOrFail();

        $this->seedAiHistory($workspace, $project, $admin);

        $run = AiRun::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->firstOrFail();

        $base = "/w/{$workspace->slug}";
        $projectBase = "{$base}/projects/{$project->slug}";

        $pages = [
            'ai' => "{$base}/ai",
            'ai-approvals' => "{$base}/ai?pane=approvals",
            'ai-runs' => "{$base}/ai?pane=runs",
            'ai-approvals-page' => "{$base}/ai/approvals",
            'ai-run-open' => "{$base}/ai/runs/{$run->uuid}",
            'project-ai' => "{$projectBase}/ai",
            'teams' => "{$base}/teams",
            'reports' => "{$base}/reports",
            'settings-general' => "{$base}/settings",
            'settings-ai' => "{$base}/settings?section=ai",
            'settings-security' => "{$base}/settings?section=security",
            'settings-statuses' => "{$base}/settings?section=statuses",
            'settings-tags' => "{$base}/settings?section=tags",
            'settings-fields' => "{$base}/settings?section=fields",
            'settings-templates' => "{$base}/settings?section=templates",
            'settings-webhooks' => "{$base}/settings?section=webhooks",
            'profile' => '/profile',
            'profile-notifications' => '/profile/notifications',
            'profile-security' => '/profile/security',
            'project-wiki' => "{$projectBase}/wiki",
            'project-wiki-page' => "{$projectBase}/wiki/{$wikiPage->slug}",
            'project-files' => "{$projectBase}/files",
            'project-activity' => "{$projectBase}/activity",
            'project-settings' => "{$projectBase}/settings",
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
                    public_path("_sweep-{$locale}-{$name}.html"),
                    str_replace(
                        [config('app.url'), 'http://localhost'],
                        '',
                        (string) $response->getContent(),
                    ),
                );
            }
        }

        $admin->forceFill(['locale' => 'ar'])->save();
    }

    /**
     * The states a URL cannot reach: an open conversation, a wiki page in the editor, a
     * settings section mid-edit. Rendered through Livewire and wrapped in the page shell so
     * the stylesheet, `lang` and `dir` are the ones the real page carries.
     */
    #[Test]
    public function it_writes_component_states_for_review(): void
    {
        $this->markTestSkippedUnlessSeeded();

        $admin = User::query()->where('email', 'admin@planvio.test')->firstOrFail();
        $workspace = Workspace::query()->firstOrFail();
        $project = Project::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->firstOrFail();
        $wikiPage = WikiPage::withoutWorkspaceScope()->where('project_id', $project->id)->firstOrFail();

        $this->seedAiHistory($workspace, $project, $admin);

        $conversation = AiConversation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('id')
            ->firstOrFail();

        foreach (['ar', 'en'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();
            $this->actingAs($admin->fresh());
            $this->get("/w/{$workspace->slug}");

            $states = [
                'state-ai-thread' => fn () => Livewire::test(AiIndex::class, ['workspace' => $workspace])
                    ->call('openConversation', (int) $conversation->getKey())
                    ->html(),

                'state-wiki-editor' => fn () => Livewire::test(WikiPageComponent::class, [
                    'workspace' => $workspace,
                    'project' => $project,
                    'page' => $wikiPage,
                ])->call('startEditing')->html(),

                'state-project-ai' => fn () => Livewire::test(ProjectAiComponent::class, [
                    'workspace' => $workspace,
                    'project' => $project,
                ])->html(),
            ];

            foreach ($states as $name => $render) {
                file_put_contents(
                    public_path("_sweep-{$locale}-{$name}.html"),
                    $this->shell($render(), $locale),
                );
            }
        }

        $admin->forceFill(['locale' => 'ar'])->save();
    }

    /**
     * The app shell around a component fragment: the same stylesheet, direction and language
     * the real page renders with, so computed styles read out of the dump are the real ones.
     */
    private function shell(string $fragment, string $locale): string
    {
        $direction = Locale::directionFor($locale);
        $css = str_replace(
            [config('app.url'), 'http://localhost'],
            '',
            (string) app(Vite::class)('resources/css/app.css'),
        );

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$locale}" dir="{$direction}" class="h-full">
        <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">{$css}</head>
        <body class="h-full bg-[var(--surface-app)] text-[var(--text-DEFAULT)] antialiased">
        <div class="h-full">{$fragment}</div>
        </body>
        </html>
        HTML;
    }

    /**
     * The AI history these screens need, written once if the demo workspace has none.
     *
     * `planvio:demo` deliberately does not create agent runs — a demo installation with no
     * provider configured could not have produced one — so without this the AI screens are
     * unreachable and the test errors on a seeded database rather than skipping. Everything
     * here is created only when it is absent, so re-running does not pile up conversations.
     */
    private function seedAiHistory(Workspace $workspace, Project $project, User $admin): void
    {
        $hasConversation = AiConversation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->exists();

        if (! $hasConversation) {
            $conversation = AiConversation::factory()->create([
                'workspace_id' => $workspace->id,
                'project_id' => $project->getKey(),
                'user_id' => $admin->getKey(),
                'title' => 'Which tasks are at risk this week?',
                'message_count' => 2,
            ]);

            AiMessage::factory()->forConversation($conversation)->create([
                'role' => AiMessageRole::User,
                'content' => 'Which tasks are at risk this week?',
            ]);

            AiMessage::factory()->forConversation($conversation)->create([
                'role' => AiMessageRole::Assistant,
                'content' => 'Three tasks are due before Friday and none of them have moved since Monday.',
                'tokens_in' => 820,
                'tokens_out' => 240,
            ]);
        }

        $hasRun = AiRun::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->exists();

        if (! $hasRun) {
            AiRun::factory()->succeeded()->create([
                'workspace_id' => $workspace->id,
                'project_id' => $project->getKey(),
                'user_id' => $admin->getKey(),
                'objective' => 'Summarise this week and flag anything slipping.',
                'summary' => 'Reviewed 24 tasks and flagged 3 as at risk.',
            ]);
        }

        $hasApproval = AiToolRun::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('approval_required', true)
            ->exists();

        if (! $hasApproval) {
            $awaiting = AiRun::factory()->awaitingApproval()->create([
                'workspace_id' => $workspace->id,
                'project_id' => $project->getKey(),
                'user_id' => $admin->getKey(),
                'objective' => 'Tidy up the backlog.',
            ]);

            AiToolRun::factory()
                ->forRun($awaiting)
                ->pendingApproval()
                ->create([
                    'risk' => AiToolRisk::Destructive,
                    'arguments' => ['task_id' => 1, 'reason' => 'Duplicate of an earlier task'],
                ]);
        }
    }

    private function markTestSkippedUnlessSeeded(): void
    {
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
