<?php

declare(strict_types=1);

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\WebManifestController;
use App\Livewire\App\Ai\Approvals as AiApprovals;
use App\Livewire\App\Ai\Index as AiWorkspace;
use App\Livewire\App\Ai\Runs as AiRuns;
use App\Livewire\App\Calendar\Index as WorkspaceCalendar;
use App\Livewire\App\Export\Index as ExportCentre;
use App\Livewire\App\Home\Index as WorkspaceHome;
use App\Livewire\App\Import\Wizard as ImportWizard;
use App\Livewire\App\Inbox\Index as Inbox;
use App\Livewire\App\MyTasks\Index as MyTasks;
use App\Livewire\App\Onboarding\Index as Onboarding;
use App\Livewire\App\Profile\Edit as ProfileEdit;
use App\Livewire\App\Profile\Notifications as ProfileNotifications;
use App\Livewire\App\Profile\Security as ProfileSecurity;
use App\Livewire\App\Projects\Activity as ProjectActivity;
use App\Livewire\App\Projects\Ai as ProjectAi;
use App\Livewire\App\Projects\Calendar as ProjectCalendar;
use App\Livewire\App\Projects\Create as ProjectCreate;
use App\Livewire\App\Projects\Files as ProjectFiles;
use App\Livewire\App\Projects\Index as ProjectIndex;
use App\Livewire\App\Projects\Settings as ProjectSettings;
use App\Livewire\App\Projects\Show as ProjectShow;
use App\Livewire\App\Projects\Timeline as ProjectTimeline;
use App\Livewire\App\Projects\Wiki as ProjectWiki;
use App\Livewire\App\Projects\WikiPage as ProjectWikiPage;
use App\Livewire\App\Recurring\Index as ProjectRecurring;
use App\Livewire\App\Reports\Index as Reports;
use App\Livewire\App\Reports\StatusReport as ProjectStatusReport;
use App\Livewire\App\Settings\Index as WorkspaceSettings;
use App\Livewire\App\Tasks\Show as TaskShow;
use App\Livewire\App\Tasks\TaskBoard as ProjectBoard;
use App\Livewire\App\Tasks\TaskList as ProjectTasks;
use App\Livewire\App\Teams\Index as Teams;
use App\Livewire\App\Time\ProjectTime;
use App\Livewire\App\Time\Sheet as TimeSheet;
use App\Livewire\App\Workspaces\Create as WorkspaceCreate;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Installation
|--------------------------------------------------------------------------
|
| First in the file because it is first in time: on a fresh extract this is the only part of
| Planvio that can answer. Kept in its own file so the wizard's routing — and the
| `not-installed` gate on it — can be read without scrolling past the product.
|
*/

require __DIR__.'/install.php';

/*
|--------------------------------------------------------------------------
| The web app manifest
|--------------------------------------------------------------------------
|
| Served rather than shipped as a file in `public/`, so its name, description and direction
| are the reader's rather than English. See WebManifestController. It answers to everybody,
| signed in or not — a manifest is fetched by the browser alongside the page it is linked
| from, and a redirect to `/login` in place of it is a console error on every screen.
|
*/

Route::get('site.webmanifest', WebManifestController::class)->name('manifest');

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Route names are part of the contract: notifications, middleware redirects
| and Blade all resolve them by name. They do not change.
|
| Sign-in is not rate limited by a `throttle` middleware. The limiter lives in
| LoginRequest because it is keyed on the submitted email together with the
| client address, which a route-level limiter cannot see.
|
*/

Route::middleware('guest')->group(function (): void {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');

    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->name('register.store');

    Route::get('forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email');

    Route::get('reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [ResetPasswordController::class, 'store'])->name('password.store');

    // The password has already been accepted at this point; the session holds only a note
    // of which account is being challenged, so the visitor is still a guest.
    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.login');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->name('two-factor.login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('logout', LogoutController::class)->name('logout');

    Route::get('verify-email', [VerifyEmailController::class, 'notice'])->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', [VerifyEmailController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('verify-email/send', [VerifyEmailController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    /*
     | Enrolment sits outside the `two-factor` middleware on purpose: it is the screen that
     | middleware sends people to, so guarding it with itself would be a redirect loop.
     */
    Route::get('security/two-factor', [TwoFactorSetupController::class, 'show'])->name('two-factor.setup');
    Route::post('security/two-factor', [TwoFactorSetupController::class, 'store'])->name('two-factor.enable');
    Route::post('security/two-factor/confirm', [TwoFactorSetupController::class, 'confirm'])
        ->name('two-factor.confirm');
    Route::post('security/two-factor/recovery-codes', [TwoFactorSetupController::class, 'recoveryCodes'])
        ->name('two-factor.recovery-codes');
    Route::delete('security/two-factor', [TwoFactorSetupController::class, 'destroy'])->name('two-factor.disable');
});

/*
|--------------------------------------------------------------------------
| Wiki page binding
|--------------------------------------------------------------------------
|
| `{page:slug}` is the one nested parameter Laravel's scoped implicit binding cannot
| resolve on its own: it derives the relationship name from the parameter — `page` ->
| `pages()` — and a project's pages are `wikiPages()` (ARCHITECTURE.md §5.6).
|
| An explicit binder is the honest fix, and it runs early enough that it has to walk the
| chain itself: `SubstituteBindings` resolves explicit binders before implicit ones, so the
| parent parameters are still raw slugs here. Every hop is constrained through a relation,
| which is what makes a slug from another workspace a 404 rather than a hit. Membership is
| a separate question, answered afterwards by `SetCurrentWorkspace` and the page policy.
|
*/

Route::bind('page', static function (mixed $value, RoutingRoute $route): mixed {
    $project = $route->parameter('project');

    if (! $project instanceof Project) {
        $workspace = $route->parameter('workspace');

        if (! $workspace instanceof Workspace) {
            $workspace = Workspace::query()->where('slug', (string) $workspace)->first();
        }

        $project = $workspace?->projects()->where('slug', (string) $project)->first();
    }

    abort_if($project === null, 404);

    return $project->wikiPages()->where('slug', (string) $value)->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| The application
|--------------------------------------------------------------------------
|
| Everything a signed-in person sees. The middleware stack is fixed and ordered:
|
|   auth        there is a user
|   verified    the address is confirmed (inert until User implements MustVerifyEmail,
|               and in place already so that turning verification on is one line rather
|               than an audit of every route)
|   workspace   the tenant is resolved and bound, and a non-member is refused
|   two-factor  enrolment is complete where the installation demands it
|
| `workspace` runs before `two-factor` because the second factor may be required by a
| workspace role, and EnsureTwoFactorConfirmed asks CurrentWorkspace which role that is.
| Reversing them would ask the question before the answer exists.
|
| `recent` is added only where a record is actually opened. It writes in terminate(), after
| the response, and recording "you looked at the project list" would tell nobody anything.
|
*/

Route::middleware(['auth', 'verified', 'workspace', 'two-factor'])->group(function (): void {

    /*
     | The front door. Nothing lives at `/` — it forwards to the workspace this person was
     | last working in, or to onboarding when they have none, which is the only honest
     | destination for an account that cannot yet see any of the product.
     */
    Route::get('/', function (CurrentWorkspace $current): RedirectResponse {
        $workspace = $current->get();

        return $workspace === null
            ? redirect()->route('workspaces.create')
            : redirect()->route('app.home', $workspace);
    })->name('home');

    Route::get('workspaces/new', WorkspaceCreate::class)->name('workspaces.create');

    Route::prefix('profile')->name('profile.')->group(function (): void {
        Route::get('/', ProfileEdit::class)->name('edit');
        Route::get('notifications', ProfileNotifications::class)->name('notifications');
        Route::get('security', ProfileSecurity::class)->name('security');
    });

    /*
     | Uploads never get a storage URL (ARCHITECTURE.md §9). The controller authorises the
     | acting user against the attachment and streams the bytes off the private disk.
     */
    Route::get('attachments/{attachment}', AttachmentController::class)->name('attachments.download');

    /*
     | The tenant surface. `scopeBindings()` is a security control, not a nicety: without it
     | `/w/acme/projects/secret-slug` would resolve a project belonging to any workspace and
     | leave the 404 to a policy that may well be asked about the wrong tenant. With it, the
     | child is looked up through its parent's relation, so a slug from another workspace
     | simply does not exist.
     */
    Route::prefix('w/{workspace:slug}')
        ->name('app.')
        ->scopeBindings()
        ->group(function (): void {

            Route::get('/', WorkspaceHome::class)->name('home');

            /*
             | Onboarding sits inside the workspace, not in front of it: the shell is drawn
             | around it, every step is skippable, and the page stays reachable afterwards
             | for whatever was left undone.
             */
            Route::get('welcome', Onboarding::class)->name('onboarding');

            Route::get('inbox', Inbox::class)->name('inbox');
            Route::get('my-tasks', MyTasks::class)->name('my-tasks');
            Route::get('calendar', WorkspaceCalendar::class)->name('calendar');
            Route::get('reports', Reports::class)->name('reports');

            /*
             | A person's own timesheet. It is a workspace-level screen rather than a
             | project one because the question it answers — "have I accounted for my
             | week" — crosses every project somebody worked on.
             */
            Route::get('time', TimeSheet::class)->name('time');

            /*
             | The AI surface. The workspace screen carries all three panes - conversation,
             | approvals, runs - and is where somebody arrives by hand. The two below exist
             | because a notification has to link straight at the thing it is about:
             | PlanvioUrl resolves them by name, and an approval request is worth little
             | if its link lands on a list.
             */
            Route::get('ai', AiWorkspace::class)->name('ai');
            Route::get('ai/approvals', AiApprovals::class)->name('ai.approvals');
            Route::get('ai/runs/{run}', AiRuns::class)->name('ai.runs.show');

            Route::get('teams', Teams::class)->name('teams');
            Route::get('settings', WorkspaceSettings::class)->name('settings');

            /*
             | Export. The screen and the file are two different things on purpose: a
             | Livewire action returning a download encodes the whole file into its JSON
             | response, so the bytes are streamed from a plain controller instead
             | (App\Http\Controllers\ExportController) and the screen links to it with its
             | filters in the query string.
             */
            Route::get('export', ExportCentre::class)->name('export');

            /*
             | The download is throttled well below the screen that links to it: choosing
             | filters is browsing, and streaming a workspace's whole task table into a
             | spreadsheet is not (config/planvio.php -> security.rate_limits).
             */
            Route::get('export/{type}/download', ExportController::class)
                ->middleware('throttle:planvio-export')
                ->name('export.download');

            Route::prefix('projects')->name('projects.')->group(function (): void {
                Route::get('/', ProjectIndex::class)->name('index');

                // Before `{project:slug}`, so "new" is never mistaken for a project slug.
                Route::get('new', ProjectCreate::class)->name('create');

                Route::prefix('{project:slug}')->middleware('recent')->group(function (): void {
                    Route::get('/', ProjectShow::class)->name('show');
                    Route::get('tasks', ProjectTasks::class)->name('tasks');
                    Route::get('board', ProjectBoard::class)->name('board');
                    Route::get('calendar', ProjectCalendar::class)->name('calendar');
                    Route::get('timeline', ProjectTimeline::class)->name('timeline');
                    Route::get('time', ProjectTime::class)->name('time');

                    /*
                     | The printable status report. Its own route rather than a mode of the
                     | reports screen: it renders in the print layout, it is linked to from
                     | outside the product, and a URL somebody can send is the whole point.
                     |
                     | It is also the most expensive render in the product — tasks,
                     | milestones, time and budget aggregated in one pass — and, because it
                     | is a shareable URL, the one most likely to be refreshed by something
                     | that is not a person.
                     */
                    Route::get('report', ProjectStatusReport::class)
                        ->middleware('throttle:planvio-report')
                        ->name('report');

                    /*
                     | Bringing work in, and the work that comes back. Both are project
                     | scoped because both write tasks into one board: an import needs the
                     | project's columns and milestones to match names against, and a
                     | recurrence is a standing instruction to that same board.
                     */
                    Route::get('import', ImportWizard::class)->name('import');
                    Route::get('recurring', ProjectRecurring::class)->name('recurring');

                    Route::get('files', ProjectFiles::class)->name('files');
                    Route::get('wiki', ProjectWiki::class)->name('wiki');
                    Route::get('wiki/{page:slug}', ProjectWikiPage::class)->name('wiki.show');
                    Route::get('activity', ProjectActivity::class)->name('activity');
                    Route::get('settings', ProjectSettings::class)->name('settings');
                    Route::get('ai', ProjectAi::class)->name('ai');
                });
            });

            /*
             | A task is addressed by id rather than by a slug it does not have. The scoped
             | binding still applies — the id is looked up through the workspace — so a task
             | number guessed from another tenant is a 404, not a 403 that confirms it.
             */
            Route::get('tasks/{task}', TaskShow::class)
                ->middleware('recent')
                ->name('tasks.show');
        });
});
