<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Auth\RegisterController;
use App\Models\AiToolRun;
use App\Models\Comment;
use App\Models\Invitation;
use App\Models\Locale;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Ai\AiApprovalRequired;
use App\Notifications\Comments\MentionedInComment;
use App\Notifications\Tasks\TaskAssigned;
use App\Notifications\WorkspaceInvitation;
use App\Services\TwoFactorService;
use App\Support\CurrentWorkspace;
use App\Support\Settings;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * The same idea as {@see RenderArabicForReviewTest}, aimed at everything *outside* the
 * signed-in product: the auth screens, the administration panel, mail, the printable
 * status report and the error pages.
 *
 *     ./php artisan test --without-tty --filter=RenderArabicPerimeterForReview
 *
 * Writes into public/ under `_perimeter-<locale>-<name>.html`, which .gitignore covers.
 * Asserts almost nothing: what it produces is meant to be looked at.
 */
#[Group('visual')]
final class RenderArabicPerimeterForReviewTest extends TestCase
{
    #[Test]
    public function it_writes_the_perimeter_in_both_languages(): void
    {
        $this->markTestSkippedUnlessSeeded();

        foreach (['ar', 'en'] as $locale) {
            /*
             * A fresh application per language. Filament mounts its navigation once and
             * keeps it on the panel, which is a container singleton — harmless in a
             * request-per-process runtime and thoroughly misleading here, where it made the
             * second language's dumps carry the first language's menu.
             */
            $this->refreshApplication();

            $this->guestScreens($locale);
            $this->signedInScreens($locale);
            $this->adminPanel($locale);
            $this->errorPages($locale);
            $this->mail($locale);
        }

        $this->admin('ar');
        $this->asDefaultLocale('en');
    }

    private function guestScreens(string $locale): void
    {
        // These are guest screens; the previous pass signed somebody in.
        app('auth')->forgetGuards();
        app(CurrentWorkspace::class)->forget();

        $this->asDefaultLocale($locale);

        $settings = app(Settings::class);
        $wasOpen = (bool) $settings->get(RegisterController::SETTING_KEY, false);
        $settings->set(RegisterController::SETTING_KEY, true);

        try {
            $pages = [
                'login' => '/login',
                'register' => '/register',
                'forgot-password' => '/forgot-password',
                'reset-password' => '/reset-password/'.str_repeat('a', 64),
            ];

            foreach ($pages as $name => $path) {
                $response = $this->get($path);
                $this->dump($locale, $name, $response->getContent(), $response->getStatusCode());
            }

            // A rejected form: the labels, the messages and the attribute names in one place.
            $this->from('/register')->post('/register', [
                'name' => '',
                'email' => 'not-an-address',
                'password' => 'short',
                'password_confirmation' => 'different',
            ]);

            $response = $this->get('/register');
            $this->dump($locale, 'register-invalid', $response->getContent(), $response->getStatusCode());
        } finally {
            $settings->set(RegisterController::SETTING_KEY, $wasOpen);
        }
    }

    private function signedInScreens(string $locale): void
    {
        $admin = $this->admin($locale);
        $workspace = Workspace::query()->firstOrFail();
        $project = Project::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->firstOrFail();

        $response = $this->actingAs($admin)->get("/w/{$workspace->slug}/projects/{$project->slug}/report");
        $this->dump($locale, 'status-report', $response->getContent(), $response->getStatusCode());

        $this->twoFactorScreens($locale, $admin);
        $this->unverifiedScreen($locale, $admin);
    }

    /**
     * The three states of the enrolment screen, plus the challenge — the places where Latin
     * strings (a QR panel, a Base32 secret, recovery codes) sit inside Arabic prose.
     */
    private function twoFactorScreens(string $locale, User $admin): void
    {
        $twoFactor = app(TwoFactorService::class);

        $original = [
            'two_factor_secret' => $admin->getRawOriginal('two_factor_secret'),
            'two_factor_recovery_codes' => $admin->getRawOriginal('two_factor_recovery_codes'),
            'two_factor_confirmed_at' => $admin->getRawOriginal('two_factor_confirmed_at'),
        ];

        try {
            $admin->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            $response = $this->actingAs($admin->fresh())->get('/security/two-factor');
            $this->dump($locale, 'two-factor-setup-off', $response->getContent(), $response->getStatusCode());

            $codes = $twoFactor->generateRecoveryCodes();
            $twoFactor->enable($admin, $twoFactor->generateSecret(), $codes);

            $response = $this->actingAs($admin->fresh())->get('/security/two-factor');
            $this->dump($locale, 'two-factor-setup-pending', $response->getContent(), $response->getStatusCode());

            $twoFactor->confirm($admin);

            $response = $this->actingAs($admin->fresh())
                ->withSession(['recoveryCodes' => $codes])
                ->get('/security/two-factor');
            $this->dump($locale, 'two-factor-setup-on', $response->getContent(), $response->getStatusCode());

            // The challenge is a guest screen. Drop the session actingAs() left behind, and
            // the workspace binding an earlier request left in the container — in production
            // both are per-request, and a leaked tenant would resolve its house language.
            app('auth')->forgetGuards();
            app(CurrentWorkspace::class)->forget();

            $response = $this->withSession([
                'auth.two_factor' => [
                    'id' => $admin->getKey(),
                    'remember' => false,
                    'expires_at' => now()->addMinutes(10)->getTimestamp(),
                ],
            ])->get('/two-factor-challenge');
            $this->dump($locale, 'two-factor-challenge', $response->getContent(), $response->getStatusCode());
        } finally {
            $admin->forceFill($original)->save();
        }
    }

    private function unverifiedScreen(string $locale, User $admin): void
    {
        $verified = $admin->getRawOriginal('email_verified_at');

        try {
            $admin->forceFill(['email_verified_at' => null])->save();

            $response = $this->actingAs($admin->fresh())->get('/verify-email');
            $this->dump($locale, 'verify-email', $response->getContent(), $response->getStatusCode());
        } finally {
            $admin->forceFill(['email_verified_at' => $verified])->save();
        }
    }

    private function adminPanel(string $locale): void
    {
        $admin = $this->admin($locale);

        $pages = [
            'admin-dashboard' => '/admin',
            'admin-users' => '/admin/users',
            'admin-user-create' => '/admin/users/create',
            'admin-workspaces' => '/admin/workspaces',
            'admin-locales' => '/admin/locales',
            'admin-locale-create' => '/admin/locales/create',
            'admin-translations' => '/admin/translations',
            'admin-ai-providers' => '/admin/ai-providers',
            'admin-ai-provider-create' => '/admin/ai-providers/create',
            'admin-ai-runs' => '/admin/ai-runs',
            'admin-ai-tool-runs' => '/admin/ai-tool-runs',
            'admin-ai-automations' => '/admin/ai-automations',
            'admin-ai-policies' => '/admin/ai-policies',
            'admin-audit-logs' => '/admin/audit-logs',
            'admin-settings' => '/admin/settings',
            'admin-system-health' => '/admin/system-health',
            'admin-system-information' => '/admin/system-information',
            'admin-maintenance-mode' => '/admin/maintenance-mode',
            'admin-ai-usage' => '/admin/ai-usage',
            'admin-project-templates' => '/admin/project-templates',
            'admin-webhook-deliveries' => '/admin/webhook-deliveries',
        ];

        foreach ($pages as $name => $path) {
            $response = $this->actingAs($admin)->get($path);
            $this->dump($locale, $name, $response->getContent(), $response->getStatusCode());
        }
    }

    private function errorPages(string $locale): void
    {
        App::setLocale($locale);
        View::share('textDirection', Locale::directionFor($locale));

        foreach (['401', '403', '404', '419', '429', '500', '503'] as $code) {
            $html = View::make('errors.'.$code, ['exception' => null, 'message' => null])->render();
            $this->dump($locale, 'error-'.$code, $html, 200);
        }
    }

    private function mail(string $locale): void
    {
        App::setLocale($locale);

        $workspace = Workspace::query()->firstOrFail();
        $task = Task::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->firstOrFail();
        $actor = User::query()->where('email', 'admin@planvio.test')->firstOrFail();
        $comment = Comment::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->first();
        $toolRun = AiToolRun::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->first();

        $messages = ['mail-task-assigned' => new TaskAssigned($task, $actor)];

        if ($comment !== null) {
            $messages['mail-mentioned'] = new MentionedInComment(
                comment: $comment,
                actor: $actor,
                excerpt: 'Can you take a look at the estimate before Friday?',
                projectId: (int) $task->project_id,
                subjectType: $task->getMorphClass(),
                subjectId: (int) $task->getKey(),
                subjectTitle: $task->key.' · '.(string) $task->title,
            );
        }

        if ($toolRun !== null && $toolRun->run !== null) {
            $messages['mail-ai-approval'] = new AiApprovalRequired($toolRun->run, $toolRun);
        }

        $invitation = Invitation::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->first()
            ?? tap(new Invitation([
                'email' => 'nadia@example.com',
                'role' => WorkspaceRole::Member,
                'token' => str_repeat('c', 64),
                'expires_at' => now()->addWeek(),
            ]), function (Invitation $invitation) use ($workspace, $actor): void {
                $invitation->workspace_id = $workspace->id;
                $invitation->invited_by = $actor->getKey();
                $invitation->setRelation('workspace', $workspace);
                $invitation->setRelation('inviter', $actor);
            });

        $messages['mail-invitation'] = new WorkspaceInvitation($invitation);

        foreach ($messages as $name => $notification) {
            try {
                $html = (string) $notification->toMail($actor)->render();
            } catch (Throwable $e) {
                $html = '<pre>'.e($e::class.': '.$e->getMessage()).'</pre>';
            }

            $this->dump($locale, $name, $html, 200);
        }
    }

    private function admin(string $locale): User
    {
        $admin = User::query()->where('email', 'admin@planvio.test')->firstOrFail();
        $admin->forceFill(['locale' => $locale])->save();

        return $admin->fresh();
    }

    private function asDefaultLocale(string $locale): void
    {
        Locale::query()->update(['is_default' => false]);
        Locale::query()->where('code', $locale)->update(['is_default' => true, 'is_enabled' => true]);
    }

    private function dump(string $locale, string $name, ?string $html, int $status): void
    {
        // The dumps carry absolute asset URLs for whatever APP_URL says; strip the origin
        // so the file can be served from any port.
        $html = str_replace([rtrim((string) config('app.url'), '/').'/', 'http://localhost/'], '/', (string) $html);

        file_put_contents(
            public_path("_perimeter-{$locale}-{$name}.html"),
            "<!-- HTTP {$status} -->\n".$html,
        );
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
