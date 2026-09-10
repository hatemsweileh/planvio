<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Http\Controllers\Auth\RegisterController;
use App\Models\AiRun;
use App\Models\Locale;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use App\Support\Settings;
use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Renders every reachable screen in Arabic and writes down every run of Latin letters that
 * survived, so a person can decide which are proper nouns and which are strings somebody
 * hard-coded into a Blade without `__()`.
 *
 *     DB_DATABASE=<seeded sqlite> ./php artisan test --without-tty --filter=EnglishLeakSweep
 *
 * The report lands in `public/_leaks-ar.txt`. It asserts nothing on its own — the judgement
 * of what is a leak belongs to a reader, not to a regex.
 */
#[Group('visual')]
final class EnglishLeakSweepTest extends TestCase
{
    #[Test]
    public function it_reports_latin_runs_in_the_arabic_render(): void
    {
        $this->markTestSkippedUnlessSeeded();

        $admin = User::query()->where('email', 'admin@planvio.test')->firstOrFail();
        $admin->forceFill(['locale' => 'ar'])->save();

        $workspace = Workspace::query()->firstOrFail();
        $projects = Project::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->get();
        $project = $projects->firstOrFail();
        $task = Task::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->firstOrFail();
        $wiki = WikiPage::withoutWorkspaceScope()->where('project_id', $project->id)->first();
        $run = AiRun::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->first();

        $w = "/w/{$workspace->slug}";
        $p = "{$w}/projects/{$project->slug}";

        $paths = [
            '/' => '/',
            '/profile' => '/profile',
            '/profile/notifications' => '/profile/notifications',
            '/profile/security' => '/profile/security',
            '/security/two-factor' => '/security/two-factor',
            '/workspaces/new' => '/workspaces/new',
            $w => $w,
            "{$w}/welcome" => "{$w}/welcome",
            "{$w}/inbox" => "{$w}/inbox",
            "{$w}/my-tasks" => "{$w}/my-tasks",
            "{$w}/calendar" => "{$w}/calendar",
            "{$w}/time" => "{$w}/time",
            "{$w}/reports" => "{$w}/reports",
            "{$w}/teams" => "{$w}/teams",
            "{$w}/export" => "{$w}/export",
            "{$w}/ai" => "{$w}/ai",
            "{$w}/ai?pane=approvals" => "{$w}/ai?pane=approvals",
            "{$w}/ai?pane=runs" => "{$w}/ai?pane=runs",
            "{$w}/ai/approvals" => "{$w}/ai/approvals",
            "{$w}/projects" => "{$w}/projects",
            "{$w}/projects/new" => "{$w}/projects/new",
            "{$w}/settings" => "{$w}/settings",
            "{$w}/settings?section=ai" => "{$w}/settings?section=ai",
            "{$w}/settings?section=security" => "{$w}/settings?section=security",
            "{$w}/settings?section=statuses" => "{$w}/settings?section=statuses",
            "{$w}/settings?section=tags" => "{$w}/settings?section=tags",
            "{$w}/settings?section=fields" => "{$w}/settings?section=fields",
            "{$w}/settings?section=templates" => "{$w}/settings?section=templates",
            "{$w}/settings?section=webhooks" => "{$w}/settings?section=webhooks",
            "{$w}/settings?section=members" => "{$w}/settings?section=members",
            $p => $p,
            "{$p}/board" => "{$p}/board",
            "{$p}/tasks" => "{$p}/tasks",
            "{$p}/calendar" => "{$p}/calendar",
            "{$p}/timeline" => "{$p}/timeline",
            "{$p}/time" => "{$p}/time",
            "{$p}/files" => "{$p}/files",
            "{$p}/wiki" => "{$p}/wiki",
            "{$p}/activity" => "{$p}/activity",
            "{$p}/recurring" => "{$p}/recurring",
            "{$p}/import" => "{$p}/import",
            "{$p}/report" => "{$p}/report",
            "{$p}/settings" => "{$p}/settings",
            "{$p}/ai" => "{$p}/ai",
            "{$w}/tasks/{$task->getKey()}" => "{$w}/tasks/{$task->getKey()}",
        ];

        if ($wiki !== null) {
            $paths["{$p}/wiki/{$wiki->slug}"] = "{$p}/wiki/{$wiki->slug}";
        }

        if ($run !== null) {
            $paths["{$w}/ai/runs/{$run->uuid}"] = "{$w}/ai/runs/{$run->uuid}";
        }

        foreach (['/login', '/register', '/forgot-password'] as $guest) {
            $paths[$guest] = $guest;
        }

        $adminPaths = [
            '/admin', '/admin/users', '/admin/users/create', '/admin/workspaces',
            '/admin/locales', '/admin/locales/create', '/admin/translations',
            '/admin/ai-providers', '/admin/ai-providers/create', '/admin/ai-runs',
            '/admin/ai-tool-runs', '/admin/ai-automations', '/admin/ai-automations/create',
            '/admin/ai-policies', '/admin/ai-policies/create', '/admin/audit-logs',
            '/admin/settings', '/admin/settings/create', '/admin/system-health',
            '/admin/system-information', '/admin/maintenance-mode', '/admin/ai-usage',
            '/admin/project-templates', '/admin/project-templates/create',
            '/admin/webhook-deliveries',
        ];

        foreach ($adminPaths as $adminPath) {
            $paths[$adminPath] = $adminPath;
        }

        $installPaths = [
            '/install', '/install/requirements', '/install/database', '/install/application',
            '/install/administrator', '/install/email', '/install/ai',
        ];

        $allowed = $this->allowList($workspace, $projects);

        // Guest screens need the installation default to be Arabic and registration open;
        // both are settings on the developer's own database, so they are put back at the end.
        $wasDefault = (string) (Locale::query()->where('is_default', true)->value('code') ?? 'en');
        $wasOpen = (bool) app(Settings::class)->get(RegisterController::SETTING_KEY, false);

        $report = [];
        $totals = [];

        foreach ($paths as $label => $path) {
            $guest = in_array($path, ['/login', '/register', '/forgot-password'], true);

            if ($guest) {
                // A visitor has no account to read a language from, so a guest screen
                // renders in whatever `locales` says is the default.
                Locale::query()->update(['is_default' => false]);
                Locale::query()->where('code', 'ar')->update(['is_default' => true, 'is_enabled' => true]);
                app(Settings::class)->set(
                    RegisterController::SETTING_KEY,
                    true,
                );

                // Both are per-request singletons in production; inside one test process
                // they survive, and a leaked tenant would hand a guest screen the
                // workspace's house language instead of the installation default.
                app('auth')->forgetGuards();
                app(CurrentWorkspace::class)->forget();

                $response = $this->get($path);
            } else {
                $response = $this->actingAs($admin->fresh())->get($path);
            }

            $status = $response->getStatusCode();
            $html = (string) $response->getContent();

            $found = $this->latinRuns($html, $allowed);

            $report[] = sprintf('### %s  [HTTP %d]  %d runs', $label, $status, count($found));

            foreach ($found as $phrase => $count) {
                $report[] = sprintf('    %-56s ×%d', $phrase, $count);
                $totals[$phrase] = ($totals[$phrase] ?? 0) + $count;
            }
        }

        // The installer runs before there is a session-driven language, so its locale comes
        // from the wizard's own picker rather than from the account.
        foreach ($installPaths as $path) {
            $response = $this->withSession(['install.locale' => 'ar'])->get($path);
            $found = $this->latinRuns((string) $response->getContent(), $allowed);

            $report[] = sprintf('### %s  [HTTP %d]  %d runs', $path, $response->getStatusCode(), count($found));

            foreach ($found as $phrase => $count) {
                $report[] = sprintf('    %-56s ×%d', $phrase, $count);
                $totals[$phrase] = ($totals[$phrase] ?? 0) + $count;
            }
        }

        Locale::query()->update(['is_default' => false]);
        Locale::query()->where('code', $wasDefault)->update(['is_default' => true]);
        app(Settings::class)->set(RegisterController::SETTING_KEY, $wasOpen);

        arsort($totals);

        $summary = ['===== DISTINCT LATIN RUNS ACROSS EVERY SCREEN ====='];

        foreach ($totals as $phrase => $count) {
            $summary[] = sprintf('  %-60s ×%d', $phrase, $count);
        }

        file_put_contents(
            public_path('_leaks-ar.txt'),
            implode("\n", array_merge($summary, [''], $report))."\n",
        );

        $this->assertTrue(true);
    }

    /**
     * Names this installation's own data carries, which are content rather than interface.
     *
     * @param Collection<int, Project> $projects
     * @return list<string>
     */
    private function allowList(Workspace $workspace, $projects): array
    {
        $words = ['Planvio', 'Acme', 'Company'];

        foreach ($projects as $project) {
            $words[] = (string) $project->name;
            $words[] = (string) $project->key;
            $words[] = (string) $project->slug;
            $words[] = (string) $project->client_name;
            $words[] = (string) $project->department;
        }

        $words[] = (string) $workspace->name;
        $words[] = (string) $workspace->slug;

        foreach (User::query()->get() as $user) {
            foreach (preg_split('/\s+/', (string) $user->name) ?: [] as $part) {
                $words[] = $part;
            }

            $words[] = (string) $user->email;
            $words[] = (string) $user->job_title;
        }

        // Rows a person could rename: statuses, tags, teams, titles. They are content the
        // way a task title is content, and their language is the language they were made in.
        $tables = [
            'tasks' => ['title'],
            'task_statuses' => ['name'],
            'project_statuses' => ['name'],
            'milestones' => ['name'],
            'tags' => ['name', 'slug'],
            'teams' => ['name', 'slug'],
            'wiki_pages' => ['title', 'slug'],
            'custom_fields' => ['name', 'key'],
            'saved_views' => ['name'],
            'project_templates' => ['name'],
            'task_checklist_items' => ['title'],
            'expenses' => ['category', 'description'],
            'time_entries' => ['description'],
            'ai_conversations' => ['title'],
        ];

        foreach ($tables as $table => $columns) {
            foreach ($columns as $column) {
                foreach (DB::table($table)->pluck($column) as $value) {
                    if (is_string($value) && trim($value) !== '') {
                        $words[] = trim($value);
                    }
                }
            }
        }

        // Timezone identifiers and currency codes are identifiers, not prose: they are the
        // same Latin string in every language, and they fill the pickers on several screens.
        foreach (timezone_identifiers_list() as $zone) {
            foreach (explode('/', $zone) as $segment) {
                $words[] = str_replace('_', ' ', $segment);
            }
        }

        foreach ((array) config('planvio.currencies', []) as $key => $value) {
            $words[] = is_string($key) ? $key : (string) $value;
        }

        // Longest first, so "Website Redesign" is removed before "Website" splits it.
        $words = array_values(array_unique(array_filter($words)));
        usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $words;
    }

    /**
     * Visible text plus the attributes a reader hears or sees, reduced to Latin runs.
     *
     * @param list<string> $allowed
     * @return array<string, int>
     */
    private function latinRuns(string $html, array $allowed): array
    {
        $runs = [];

        foreach ($this->visibleText($html) as $part) {
            // One text node at a time, whitespace collapsed: a run must not be stitched
            // together across two elements that merely sit next to each other.
            $part = trim((string) preg_replace('/\s+/u', ' ', $part));

            if ($part === '') {
                continue;
            }

            foreach ($allowed as $word) {
                $part = str_ireplace($word, ' ', $part);
            }

            preg_match_all('/[A-Za-z][A-Za-z\'’]*(?:[ \-][A-Za-z][A-Za-z\'’]*)*/u', $part, $matches);

            foreach ($matches[0] as $run) {
                $run = trim($run);

                if ($run === '' || mb_strlen($run) < 2) {
                    continue;
                }

                $runs[$run] = ($runs[$run] ?? 0) + 1;
            }
        }

        ksort($runs);

        return $runs;
    }

    /** @return list<string> */
    private function visibleText(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);

        foreach ($xpath->query('//script|//style|//head|//template|//noscript') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $parts = [];

        foreach ($xpath->query('//text()') ?: [] as $node) {
            if ($node instanceof DOMText) {
                $parts[] = $node->textContent;
            }
        }

        foreach (['placeholder', 'title', 'aria-label', 'alt'] as $attribute) {
            foreach ($xpath->query('//*[@'.$attribute.']') ?: [] as $node) {
                if ($node instanceof DOMElement) {
                    $parts[] = $node->getAttribute($attribute);
                }
            }
        }

        return $parts;
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
