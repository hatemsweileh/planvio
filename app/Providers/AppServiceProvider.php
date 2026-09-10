<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Http\Middleware\SetCurrentWorkspace;
use App\Http\Middleware\SetLocale;
use App\Models\Activity;
use App\Models\AiAutomation;
use App\Models\AiConversation;
use App\Models\AiMemory;
use App\Models\AiMessage;
use App\Models\AiPolicy;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\AiToolRun;
use App\Models\AiUsageDaily;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\CommentReaction;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Expense;
use App\Models\Favorite;
use App\Models\Invitation;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectStatus;
use App\Models\ProjectTemplate;
use App\Models\RecentItem;
use App\Models\RecurringTask;
use App\Models\SavedView;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\TaskWatcher;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\WikiPage;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Rules\StrongPassword;
use App\Services\Translation\DatabaseTranslationLoader;
use App\Services\Translation\TranslationCatalogue;
use App\Services\Translation\TranslationRepository;
use App\Services\Uploads\ScannerFactory;
use App\Services\Uploads\ScansUploads;
use App\Support\Branding;
use App\Support\CurrentWorkspace;
use App\Support\RateLimits;
use App\Support\Settings;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Requests a minute for one API token.
     *
     * Chosen for the shape of the traffic rather than for a round number: an integration
     * syncing a board walks a paginated list and then reads a handful of records, which is
     * bursty in the tens and never sustained in the hundreds. Two a second leaves room for
     * that and still stops a retry loop from becoming a load test on shared hosting.
     */
    private const TOKEN_REQUESTS_PER_MINUTE = 120;

    /**
     * And for a caller with no valid token. Deliberately much lower: every one of these is a
     * request that is about to be refused, and sending a great many of them is what guessing
     * a token looks like.
     */
    private const ANONYMOUS_REQUESTS_PER_MINUTE = 30;

    public function register(): void
    {
        /*
         | All three are per-request state that only means anything if there is exactly one
         | of it.
         |
         | CurrentWorkspace holds the tenant binding — `set()` from the middleware has to be
         | visible to the global scope that reads it, and `runFor()` has to restore what was
         | bound before. (AuthServiceProvider binds it too, for the same reason: policies
         | depend on it. Registering twice is harmless — nothing resolves the container
         | during registration — and neither provider should have to assume the other ran.)
         |
         | Settings memoises decoded values for the request, so a second instance would
         | re-read and re-decrypt everything the first one already had. Branding sits on top
         | of Settings and is read once per page render.
         */
        $this->app->singleton(CurrentWorkspace::class);
        $this->app->singleton(Settings::class);
        $this->app->singleton(Branding::class);

        /*
         | The upload gate takes a scanner and never asks which one it is (§9). Bound rather
         | than resolved inside the Action so that "this installation runs ClamAV" is a
         | configuration fact and not a branch in the middle of a security control, and
         | bound per resolution rather than as a singleton because a scanner holds nothing
         | between files.
         */
        $this->app->bind(ScansUploads::class, static fn (): ScansUploads => ScannerFactory::fromConfig());

        $this->registerTranslations();
    }

    /**
     * Put the database in front of `lang/`, and give the catalogue tools somewhere to live.
     *
     * `extend()`, not `singleton()`, and that is not a stylistic preference.
     * `TranslationServiceProvider` is *deferred*: it is not registered when this provider is,
     * it is registered the first time anything resolves `translation.loader` — which is after
     * this method has run. A `singleton()` here is therefore overwritten by the framework's
     * own binding a moment later, silently, and every override in the database stops being
     * read. An extender is applied to whatever the container finally builds, so it survives
     * that registration and wraps the real loader.
     *
     * The replacement is built from the loader it replaces rather than from a path list of
     * its own, so it inherits Laravel's own paths — the framework's shipped `auth`,
     * `passwords`, `pagination` and `validation` catalogues, then this installation's
     * `lang/` — along with any namespace a package registered.
     *
     * {@see TranslationCatalogue} is handed the same paths, because it has to decide whether
     * a dotted key such as `auth.failed` addresses a group file or is merely a sentence with
     * a full stop in it, and it can only answer that by knowing which group files exist. Get
     * that wrong and `lang:scan` writes `auth.failed` into `en.json`, where — JSON being
     * consulted first — it would shadow the group file and put the raw key on the sign-in
     * screen.
     *
     * {@see TranslationRepository} is a singleton for the same reason {@see Settings} is: it
     * memoises the rows a request reads, and a second instance would re-read them all.
     */
    private function registerTranslations(): void
    {
        $this->app->singleton(TranslationRepository::class);

        $this->app->extend('translation.loader', static function (Loader $loader, Application $app): DatabaseTranslationLoader {
            $replacement = new DatabaseTranslationLoader(
                $app->make(Filesystem::class),
                $loader instanceof FileLoader ? $loader->paths() : [$app->langPath()],
                $app->make(TranslationRepository::class),
            );

            // Whatever was registered on the original — a package's `loadTranslationsFrom`,
            // an extra JSON path — has to come across, or replacing the loader would quietly
            // unregister it.
            if ($loader instanceof FileLoader) {
                foreach ($loader->namespaces() as $namespace => $hint) {
                    $replacement->addNamespace($namespace, $hint);
                }

                foreach ($loader->jsonPaths() as $path) {
                    $replacement->addJsonPath($path);
                }
            }

            return $replacement;
        });

        // Laravel binds the loader under a string key only. Aliasing the contract lets the
        // lang:* commands and the catalogue type-hint an interface rather than a magic
        // string, and keeps a test free to swap in a plain FileLoader.
        $this->app->alias('translation.loader', Loader::class);

        $this->app->singleton(TranslationCatalogue::class, static function (Application $app): TranslationCatalogue {
            $loader = $app->make('translation.loader');

            return new TranslationCatalogue(
                $app->make(Filesystem::class),
                $loader,
                $app->basePath(),
                $loader instanceof FileLoader ? $loader->paths() : [$app->langPath()],
                (string) config('app.fallback_locale', 'en'),
            );
        });
    }

    /**
     * Stable aliases for every model that can sit on the other side of a polymorphic
     * relation.
     *
     * Without a map, Laravel writes the fully-qualified class name into every `*_type`
     * column. Three problems follow from that, and this closes all three:
     *
     *  - It leaks internal structure. ActivityResource returns `subject_type` to API
     *    consumers, so an integration would be reading `App\Models\Task` and, worse, could
     *    reasonably start depending on it.
     *  - It couples the schema to the namespace. Moving or renaming a class orphans every
     *    row that referenced it, silently, with no migration to notice.
     *  - It widens what a morph column can name. ChecksWorkspaceAccess::findMorphed()
     *    resolves a class out of that column; the guard there already restricts it to an
     *    Eloquent model, but enforceMorphMap() means an unmapped value fails outright
     *    rather than resolving to whatever happens to exist.
     *
     * Aliases are singular snake_case and are PERMANENT: changing one after a release would
     * orphan rows exactly the way a class rename does today.
     *
     * @var array<string, class-string<Model>>
     */
    private const MORPH_MAP = [
        'activity' => Activity::class,
        'ai_automation' => AiAutomation::class,
        'ai_conversation' => AiConversation::class,
        'ai_memory' => AiMemory::class,
        'ai_message' => AiMessage::class,
        'ai_policy' => AiPolicy::class,
        'ai_provider' => AiProvider::class,
        'ai_run' => AiRun::class,
        'ai_setting' => AiSetting::class,
        'ai_tool_run' => AiToolRun::class,
        'ai_usage_daily' => AiUsageDaily::class,
        'attachment' => Attachment::class,
        'audit_log' => AuditLog::class,
        'comment' => Comment::class,
        'comment_reaction' => CommentReaction::class,
        'custom_field' => CustomField::class,
        'custom_field_value' => CustomFieldValue::class,
        'expense' => Expense::class,
        'favorite' => Favorite::class,
        'invitation' => Invitation::class,
        'milestone' => Milestone::class,
        'project' => Project::class,
        'project_member' => ProjectMember::class,
        'project_status' => ProjectStatus::class,
        'project_template' => ProjectTemplate::class,
        'recent_item' => RecentItem::class,
        'recurring_task' => RecurringTask::class,
        'saved_view' => SavedView::class,
        'setting' => Setting::class,
        'tag' => Tag::class,
        'task' => Task::class,
        'task_checklist_item' => TaskChecklistItem::class,
        'task_dependency' => TaskDependency::class,
        'task_status' => TaskStatus::class,
        'task_watcher' => TaskWatcher::class,
        'team' => Team::class,
        'team_member' => TeamMember::class,
        'time_entry' => TimeEntry::class,
        'user' => User::class,
        'webhook' => Webhook::class,
        'webhook_delivery' => WebhookDelivery::class,
        'wiki_page' => WikiPage::class,
        'workspace' => Workspace::class,
        'workspace_member' => WorkspaceMember::class,
    ];

    public function boot(): void
    {
        Relation::enforceMorphMap(self::MORPH_MAP);

        /*
         | `Password::default()` anywhere in the application — form requests, the installer's
         | first-admin screen, the admin panel's user editor — now resolves to the policy an
         | administrator configured, instead of Laravel's eight-character floor.
         */
        Password::defaults(static fn (): Password => StrongPassword::rule());

        /*
         | An N+1 query is invisible on a developer's laptop with twelve tasks and fatal on a
         | shared host with a real board. Turning lazy loading into an exception outside
         | production is what makes it show up while there is still somebody looking.
         |
         | Model::unguard() is deliberately never called, here or anywhere. Every model
         | declares an explicit $fillable, and mass assignment is a security control rather
         | than a convenience to be switched off (CLAUDE.md).
         */
        Model::preventLazyLoading(! $this->app->isProduction());

        $this->keepTenancyAcrossLivewireUpdates();
        $this->limitTheApi();
        $this->limitTheWeb();
    }

    /**
     * The budgets for signed-in page traffic and for the four operations that cost real
     * work (`config/planvio.php` → `security.rate_limits`, which carries the reasoning
     * behind each number).
     *
     * ## Keyed on the account, not the address
     *
     * `planvio-web` is charged to the user id. An address is the wrong key for the product
     * surface: a whole office reaches Planvio from one NAT address, and one person's stuck
     * poller would spend everybody else's allowance. Signed-out traffic has no id and falls
     * back to the address, at its own lower number — that surface is three forms, each
     * already throttled far harder on the submitted email plus the address.
     *
     * ## Why the expensive four are separate limiters
     *
     * A search, an agent run, an export and a status report are each worth several hundred
     * ordinary requests. Folding them into `planvio-web` would mean either a ceiling too
     * low for normal browsing or one too high to be a limit on them at all. They are
     * charged twice on purpose — once here, once against the web bucket — because the
     * cheap-request budget is about the server and the expensive-request budget is about
     * the operation.
     *
     * The two that arrive on Livewire's shared update endpoint cannot be reached by route
     * middleware at all and charge themselves from inside the component instead
     * ({@see RateLimits}). They read the same configured ceiling but keep their own
     * counter: the middleware hashes its key with the limiter's name, so the product's
     * search and the API's search are two buckets of sixty rather than one shared sixty.
     * That is the right way round — a script walking the API should not be able to make
     * the palette stop answering for the person who wrote it.
     *
     * ## Why here rather than in the routes files
     *
     * The same reason as `planvio-api`: a production install runs `route:cache`, the routes
     * file is then never loaded, and a limiter declared there would be missing at exactly
     * the moment `throttle:` asks for it.
     */
    private function limitTheWeb(): void
    {
        RateLimiter::for('planvio-web', static function (Request $request): Limit {
            $user = $request->user();

            return $user instanceof User
                ? Limit::perMinute(RateLimits::perMinute(RateLimits::WEB))->by('web-user:'.$user->getKey())
                : Limit::perMinute(RateLimits::perMinute(RateLimits::GUEST))->by('web-guest:'.(string) $request->ip());
        });

        $expensive = [
            'planvio-search' => RateLimits::SEARCH,
            'planvio-ai-run' => RateLimits::AI_RUNS,
            'planvio-export' => RateLimits::EXPORTS,
            'planvio-report' => RateLimits::REPORTS,
        ];

        foreach ($expensive as $name => $bucket) {
            RateLimiter::for($name, static function (Request $request) use ($bucket): Limit {
                $user = $request->user();

                $key = $user instanceof User
                    ? 'user:'.$user->getKey()
                    : 'ip:'.(string) $request->ip();

                return Limit::perMinute(RateLimits::perMinute($bucket))->by($bucket.':'.$key);
            });
        }
    }

    /**
     * The budget every `/api/v1` request is charged against (`docs/API.md`).
     *
     * ## Per token, not per user
     *
     * The key is the personal access token, so a runaway integration cannot spend the
     * allowance its author needs for the one they are debugging, and revoking a token
     * revokes its budget with it. Sanctum's `currentAccessToken()` is the identity that
     * matters here — the account behind it may hold several tokens doing entirely different
     * jobs, and charging them to one bucket would make one script's retry loop look like the
     * other's outage.
     *
     * A session-authenticated request — the same routes reached from a browser that is
     * already signed in — has a `TransientToken` with no id, so it falls back to the user.
     * An unauthenticated request has neither and is keyed on the address, at a much lower
     * rate: those requests are failing `auth:sanctum` anyway, and the only thing anybody
     * sends a lot of them for is guessing tokens.
     *
     * ## Why it is registered here rather than in routes/api.php
     *
     * A production install runs `route:cache`, and a cached route table means the routes file
     * is never loaded. A limiter defined there would be missing at exactly the moment it
     * matters, and `throttle:planvio-api` would throw on every request.
     */
    private function limitTheApi(): void
    {
        RateLimiter::for('planvio-api', static function (Request $request): Limit {
            $user = $request->user();

            if (! $user instanceof User) {
                return Limit::perMinute(self::ANONYMOUS_REQUESTS_PER_MINUTE)
                    ->by('api-anon:'.(string) $request->ip());
            }

            $token = $user->currentAccessToken();
            $tokenId = $token instanceof PersonalAccessToken ? (int) $token->getKey() : null;

            return Limit::perMinute(self::TOKEN_REQUESTS_PER_MINUTE)->by(
                $tokenId === null
                    ? 'api-user:'.$user->getKey()
                    : 'api-token:'.$tokenId,
            );
        });
    }

    /**
     * Livewire's update endpoint is one route, `POST /livewire/update`, and it carries the
     * `web` group's middleware — not the middleware of the page the component is sitting on.
     * Left alone, that means the second and every subsequent interaction with a component
     * happens with no workspace bound: {@see WorkspaceScope} goes inert
     * and every query the component makes widens to the whole installation. Policies would
     * still refuse a foreign record, but a list would have been built before anything asked
     * a policy anything.
     *
     * Registering these as persistent makes Livewire re-run them against the original page's
     * route before the component is hydrated, so an update is authorised, gated and scoped
     * exactly the way the page load was. `SubstituteBindings` and `Authenticate` are already
     * persistent by default, which is what resolves `{workspace:slug}` back into a model for
     * {@see SetCurrentWorkspace} to bind.
     */
    private function keepTenancyAcrossLivewireUpdates(): void
    {
        Livewire::addPersistentMiddleware([
            EnsureEmailIsVerified::class,
            SetCurrentWorkspace::class,
            SetLocale::class,
            EnsureTwoFactorConfirmed::class,
        ]);
    }
}
