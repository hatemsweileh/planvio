<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\WorkspaceAttributes;
use App\Models\AiProvider;
use App\Models\AiSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Support\SecretScrubber;
use App\Support\Version;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\ComponentHookRegistry;
use ReflectionProperty;
use Throwable;
use WeakMap;

/**
 * The install itself: twelve steps, one per request, resumable.
 *
 * ### One step per request
 *
 * The progress screen polls, and each poll runs exactly one {@see InstallStep}. That is what
 * makes the progress real rather than an animation — the step that is on screen is the step
 * that is running — and it keeps every individual request short enough to finish inside the
 * `max_execution_time` a shared host allows. Migrations are the long one, which is why they
 * get a request to themselves.
 *
 * ### Runtime configuration is reapplied every time
 *
 * A step cannot rely on the previous request having configured anything: PHP forgot it. So
 * {@see self::applyRuntimeConfiguration()} runs before every step, pointing the default
 * database connection at the credentials in the plan and settling the application key. The
 * `Environment` step is where that first happens and where it is visible to the person
 * watching; the other eleven get the same treatment silently.
 *
 * ### Every step is safe to repeat
 *
 * A retry after a failure resumes at the failed step, and the steps before it are skipped
 * because the checkpoint says so — but each one is also written to be idempotent on its own,
 * because a checkpoint file that could not be written is not a reason to create a second
 * administrator.
 *
 * ### Nothing technical reaches the browser
 *
 * Every failure leaves as an {@see InstallationFailed} carrying four safe fields. The
 * underlying exception goes to the log against a reference id and no further (spec §140).
 */
final class Installer
{
    /**
     * Directories that must exist before Planvio can accept a single upload.
     *
     * @var list<string>
     */
    private const STORAGE_DIRECTORIES = [
        'app/private',
        'app/public',
        'framework/cache/data',
        'framework/sessions',
        'framework/testing',
        'framework/views',
        'logs',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly InstallPaths $paths,
        private readonly InstallCheckpoint $checkpoint,
        private readonly EnvWriter $env,
        private readonly DatabaseTester $databaseTester,
        private readonly CreateWorkspace $createWorkspace,
        /*
         | Whether this process may build the configuration cache.
         |
         | True everywhere the container assembles an Installer, which is every real
         | installation. The test suite passes false: `config:cache` writes into this
         | repository's own bootstrap/cache and boots a second application to do it, neither
         | of which belongs in a test that is meant to be hermetic.
         */
        private readonly bool $cacheConfiguration = true,
    ) {}

    public function checkpoint(): InstallCheckpoint
    {
        return $this->checkpoint;
    }

    /**
     * Run the next outstanding step, or return null when there is nothing left to do.
     *
     * @throws InstallationFailed
     */
    public function runNext(InstallPlan $plan): ?InstallStep
    {
        $step = $this->checkpoint->nextStep();

        if ($step === null) {
            return null;
        }

        $this->run($step, $plan);

        return $step;
    }

    /**
     * @throws InstallationFailed
     */
    public function run(InstallStep $step, InstallPlan $plan): void
    {
        $this->checkpoint->start();
        $this->applyRuntimeConfiguration($plan);

        try {
            match ($step) {
                InstallStep::Environment => $this->environment(),
                InstallStep::EnvFile => $this->writeEnvironmentFile($plan),
                InstallStep::Database => $this->verifyDatabase($plan),
                InstallStep::Migrations => $this->migrate(),
                InstallStep::Seed => $this->seed(),
                InstallStep::Administrator => $this->administrator($plan),
                InstallStep::Workspace => $this->workspace($plan),
                InstallStep::Ai => $this->ai($plan),
                InstallStep::Storage => $this->storage(),
                InstallStep::Optimize => $this->optimize(),
                InstallStep::Verify => $this->verify($plan),
                InstallStep::Lock => $this->lock(),
            };
        } catch (InstallationFailed $failure) {
            $this->checkpoint->fail($failure);

            throw $failure;
        } catch (Throwable $e) {
            $failure = $this->failure(
                $step,
                __('Planvio hit an unexpected problem while :step.', ['step' => mb_strtolower($step->label())]),
                __('Try this step again. If it keeps failing, the reference below matches a full description of the fault in storage/logs.'),
                $e,
            );

            $this->checkpoint->fail($failure);

            throw $failure;
        }

        $this->checkpoint->complete($step);
    }

    /* ------------------------------------------------------------------ *
     * Runtime configuration
     * ------------------------------------------------------------------ */

    /**
     * Point this process at the installation being created.
     *
     * The database credentials have not been written anywhere the framework reads yet — and
     * even once `.env` exists, this request's configuration was loaded before it did. So the
     * connection is configured here and purged, which discards any handle a previous step
     * opened against the old settings.
     */
    private function applyRuntimeConfiguration(InstallPlan $plan): void
    {
        // Migrations on a large schema outlast the default 30 seconds on some hosts. Best
        // effort: `set_time_limit` is disabled outright in some hardened configurations.
        @set_time_limit(0);

        $connection = $plan->database->connectionName();

        config([
            'app.name' => $plan->application->name,
            'app.url' => rtrim($plan->application->url, '/'),
            'app.locale' => $plan->application->locale,
            'database.default' => $connection,
            'database.connections.'.$connection => $plan->database->connectionConfig(),
        ]);

        if (blank(config('app.key'))) {
            config(['app.key' => $this->env->appKey()]);
        }

        DB::purge($connection);
        DB::setDefaultConnection($connection);
    }

    /* ------------------------------------------------------------------ *
     * The steps
     * ------------------------------------------------------------------ */

    private function environment(): void
    {
        if (blank(config('app.key'))) {
            throw $this->failure(
                InstallStep::Environment,
                __('Planvio could not generate an application key.'),
                __('The application key encrypts sessions and stored credentials. Make sure the Planvio folder is writable and try again.'),
            );
        }
    }

    private function writeEnvironmentFile(InstallPlan $plan): void
    {
        try {
            $this->env->write($plan, $this->env->appKey());
        } catch (Throwable $e) {
            throw $this->failure(
                InstallStep::EnvFile,
                __('Planvio could not save its configuration file.'),
                __('The folder containing public/ must be writable so that .env can be created. In cPanel File Manager set its permissions to 755 and run this step again.'),
                $e,
            );
        }
    }

    private function verifyDatabase(InstallPlan $plan): void
    {
        $result = $this->databaseTester->test($plan->database);

        if (! $result->ok()) {
            throw $this->failure(
                InstallStep::Database,
                $result->message,
                $result->detail ?? __('Go back to the database step and check the details you entered.'),
            );
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            throw $this->failure(
                InstallStep::Database,
                __('Planvio connected to the database server but could not open a connection of its own.'),
                __('Check that the database user is allowed more than one connection, then run this step again.'),
                $e,
            );
        }
    }

    private function migrate(): void
    {
        try {
            $exitCode = Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            throw $this->failure(
                InstallStep::Migrations,
                __('Planvio could not create its database tables.'),
                __('The database user needs CREATE, ALTER, INDEX and REFERENCES privileges. In cPanel → MySQL Databases, re-add the user to the database with ALL PRIVILEGES and run this step again.'),
                $e,
            );
        }

        if ($exitCode !== 0) {
            throw $this->failure(
                InstallStep::Migrations,
                __('The database refused one of the changes Planvio needs to make.'),
                __('This is almost always a missing privilege or a database that already contains conflicting tables. Use an empty database, or grant the user ALL PRIVILEGES, and run this step again.'),
            );
        }
    }

    private function seed(): void
    {
        try {
            $exitCode = Artisan::call('db:seed', [
                '--class' => DefaultDataSeeder::class,
                '--force' => true,
            ]);
        } catch (Throwable $e) {
            throw $this->failure(
                InstallStep::Seed,
                __('Planvio could not add its default data.'),
                __('The tables exist but could not be written to. Check that the database user has INSERT and UPDATE privileges, then run this step again.'),
                $e,
            );
        }

        if ($exitCode !== 0) {
            throw $this->failure(
                InstallStep::Seed,
                __('Planvio could not add its default data.'),
                __('Run this step again. If it keeps failing, the reference below matches a full description in storage/logs.'),
            );
        }
    }

    /**
     * The first account, and the only one that starts as a platform administrator.
     *
     * Matched on the address so a retry updates rather than duplicates. The address is
     * marked verified because the person creating it is standing at the server: sending them
     * a confirmation link through mail settings that may not have been configured yet would
     * lock them out of the installation they just performed.
     */
    private function administrator(InstallPlan $plan): void
    {
        $account = $plan->administrator;

        $user = User::withTrashed()->firstOrNew(['email' => $account->email]);

        $user->fill([
            'name' => $account->name,
            'password' => $account->password,
            'timezone' => $plan->application->timezone,
            'locale' => $plan->application->locale,
            'is_admin' => true,
            'is_active' => true,
        ]);

        $user->deleted_at = null;
        $user->email_verified_at ??= now();
        $user->save();
    }

    /**
     * The first workspace, created through the same Action the product uses.
     *
     * Nothing here re-implements the seeding of statuses, tags or the `ai_settings` row —
     * `CreateWorkspace` owns that, and an installer that built a workspace its own way would
     * produce a tenant subtly unlike every workspace created afterwards.
     */
    private function workspace(InstallPlan $plan): void
    {
        $owner = $this->administratorRecord($plan);

        $existing = Workspace::query()->oldest('id')->first();

        if ($existing !== null) {
            return;
        }

        ($this->createWorkspace)($owner, new WorkspaceAttributes(
            name: $plan->application->workspaceName(),
            timezone: $plan->application->timezone,
            locale: $plan->application->locale,
            currency: $plan->application->currency,
            dateFormat: $plan->application->dateFormat,
        ));
    }

    /**
     * The AI configuration, whether or not there is one.
     *
     * A skipped step is still a step: it records that AI is off, which is a decision, and it
     * leaves the global `ai_settings` row the seeder created exactly as it is. Turning AI on
     * later is a screen in the admin panel, not a reinstall.
     */
    private function ai(InstallPlan $plan): void
    {
        $credentials = $plan->ai;
        $workspace = Workspace::query()->oldest('id')->first();

        if (! $credentials->enabled) {
            $this->applyAiSettings(null, false, $plan, $workspace);

            return;
        }

        $provider = AiProvider::query()->where('is_default', true)->first() ?? new AiProvider;

        $provider->fill([
            'name' => $credentials->providerName(),
            'driver' => $credentials->driver,
            'base_url' => $credentials->resolvedBaseUrl(),
            'model' => $credentials->resolvedModel(),
            'timeout_seconds' => (int) config('ai.limits.request_timeout_seconds', 60),
            'is_active' => true,
            'is_default' => true,
        ]);

        // Assigned outside fill() so an empty key never blanks a previously stored one on a
        // retry; the column carries the encrypted cast either way.
        if ($credentials->apiKey !== null) {
            $provider->api_key = $credentials->apiKey;
        }

        $provider->save();

        $this->applyAiSettings((int) $provider->getKey(), true, $plan, $workspace);
    }

    private function applyAiSettings(?int $providerId, bool $enabled, InstallPlan $plan, ?Workspace $workspace): void
    {
        $attributes = [
            'is_enabled' => $enabled,
            'ai_provider_id' => $providerId,
            'default_mode' => $plan->ai->mode,
        ];

        AiSetting::query()->updateOrCreate(['workspace_id' => null], $attributes);

        if ($workspace !== null) {
            AiSetting::query()->updateOrCreate(
                ['workspace_id' => $workspace->getKey()],
                $attributes,
            );
        }
    }

    /**
     * The directories Planvio writes to, and the one link it would like to have.
     *
     * The symlink is best effort by design. Plenty of shared hosts disable `symlink()`, and
     * the only thing that misses it is avatar and logo delivery — not a reason to fail an
     * installation, so its absence is noted in the log and the wizard moves on.
     */
    private function storage(): void
    {
        foreach (self::STORAGE_DIRECTORIES as $directory) {
            $path = $this->paths->storagePath($directory);

            if (is_dir($path)) {
                continue;
            }

            if (! @mkdir($path, 0755, true) && ! is_dir($path)) {
                throw $this->failure(
                    InstallStep::Storage,
                    __('Planvio could not create the folder :path.', ['path' => 'storage/'.$directory]),
                    __('In cPanel File Manager set the permissions of storage/ to 755 and apply them to all subdirectories, then run this step again.'),
                );
            }
        }

        $this->linkPublicStorage();
    }

    private function linkPublicStorage(): void
    {
        $link = $this->paths->publicPath('storage');

        if (file_exists($link) || is_link($link)) {
            return;
        }

        if (! function_exists('symlink')) {
            Log::info('Installer skipped the public storage symlink: symlink() is disabled on this host.');

            return;
        }

        try {
            @symlink($this->paths->storagePath('app/public'), $link);
        } catch (Throwable) {
            Log::info('Installer could not create the public storage symlink.');
        }
    }

    /**
     * Build the configuration cache, if it can be built safely.
     *
     * `config:cache` boots a second application inside this process to read a clean copy of
     * the configuration — which is exactly what makes it correct here, because that copy
     * reads the `.env` this installation just wrote rather than the temporary drivers the
     * wizard is running on. The cost is that it leaves the global container and the facade
     * root pointing at that second application, so both are put back afterwards; without
     * that, the rest of this request would be talking to a different application than the one
     * it started in.
     *
     * The container is not the only thing that second boot disturbs. Booting it runs every
     * service provider again, and Livewire's ends in `ComponentHookRegistry::boot()`, which
     * replaces a process-wide WeakMap of component => attached hooks with an empty one. The
     * Install component rendering *this* request is in that map. Losing it meant the progress
     * screen dehydrated with no hooks attached, so its snapshot came back missing `children`,
     * `errors`, `scripts` and the rest — and the very next poll, built from that snapshot,
     * died with "Undefined array key children". The installation stopped at step ten of
     * twelve, in a browser, every time. So the map is carried across the call with everything
     * else.
     *
     * A failure is a warning, never a fault. The cache is a speed-up; Planvio runs without it.
     */
    private function optimize(): void
    {
        $application = $this->app;
        $cached = $application->bootstrapPath('cache/config.php');
        $hooks = $this->componentHooks();

        // Always: a cache built before `.env` existed describes an application that no longer
        // does. Removing it is correct whether or not a new one is built afterwards.
        if (is_file($cached)) {
            @unlink($cached);
        }

        if (! $this->cacheConfiguration) {
            return;
        }

        try {
            Artisan::call('config:cache');
        } catch (Throwable $e) {
            if (is_file($cached)) {
                @unlink($cached);
            }

            Log::warning('Installer could not cache the configuration.', ['exception' => $e::class]);
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($application);
            $application->instance('app', $application);
            Container::setInstance($application);
            $this->restoreComponentHooks($hooks);
        }
    }

    /**
     * Livewire's component => hooks map, or null when it cannot be read.
     *
     * Reached by reflection because Livewire exposes no accessor, and defensively so: if a
     * future version renames or removes the property there is nothing to carry across, and
     * the installer must not fail over a speed-up. See {@see self::optimize()} for why it is
     * carried at all.
     */
    private function componentHooks(): ?object
    {
        if (! property_exists(ComponentHookRegistry::class, 'components')) {
            return null;
        }

        try {
            $property = new ReflectionProperty(ComponentHookRegistry::class, 'components');
            $value = $property->getValue();

            return $value instanceof WeakMap ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function restoreComponentHooks(?object $hooks): void
    {
        if ($hooks === null) {
            return;
        }

        try {
            (new ReflectionProperty(ComponentHookRegistry::class, 'components'))->setValue(null, $hooks);
        } catch (Throwable) {
            // Nothing to be done, and nothing worth failing the installation over.
        }
    }

    /**
     * Read everything back before claiming success.
     *
     * The step exists because "no exception was thrown" is not the same as "the installation
     * works": a migration can succeed against a connection that is then unreachable, and a
     * `.env` can be written to a path the web server does not read.
     */
    private function verify(InstallPlan $plan): void
    {
        $checks = [
            'tables' => Schema::hasTable('users') && Schema::hasTable('workspaces') && Schema::hasTable('settings'),
            'administrator' => $this->administratorExists($plan),
            'workspace' => Workspace::query()->exists(),
            'env' => is_file($this->paths->env) && EnvFile::fromFile($this->paths->env)->get('APP_KEY') !== null,
        ];

        $failed = array_keys(array_filter($checks, static fn (bool $ok): bool => ! $ok));

        if ($failed !== []) {
            throw $this->failure(
                InstallStep::Verify,
                __('Planvio finished the installation but could not confirm it afterwards.'),
                __('Nothing has been locked, so it is safe to run this step again. If it keeps failing, check that the database is reachable and that .env is readable by the web server.'),
            );
        }

        $lockDirectory = dirname($this->paths->lockFile);

        if (! is_dir($lockDirectory) && ! @mkdir($lockDirectory, 0755, true) && ! is_dir($lockDirectory)) {
            throw $this->failure(
                InstallStep::Verify,
                __('Planvio cannot create the folder that holds the installation lock.'),
                __('Set the permissions of storage/app to 755 in cPanel File Manager and run this step again.'),
            );
        }
    }

    /**
     * The lock, and the last thing this class ever does (spec §74).
     *
     * From here on `EnsureNotInstalled` refuses every installer route server-side. There is
     * no route that removes the file: undoing an installation means deleting it from the
     * filesystem *and* dropping the tables, deliberately two acts, neither of which a visitor
     * can perform.
     */
    private function lock(): void
    {
        $contents = json_encode([
            'version' => Version::app(),
            'db_version' => Version::db(),
            'installed_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($this->paths->lockFile, (string) $contents, LOCK_EX) === false) {
            throw $this->failure(
                InstallStep::Lock,
                __('Planvio could not write the installation lock.'),
                __('Everything else succeeded. Set the permissions of storage/app to 755 in cPanel File Manager and run this step again — the installer is not closed until the lock exists.'),
            );
        }

        @chmod($this->paths->lockFile, 0600);

        /*
         | Only now, and deliberately after the lock: `APP_INSTALLED` is the same answer said
         | twice, and saying it any earlier closes the wizard while it is still running. The
         | lock is the authority, so a `.env` that has turned read-only since step two costs
         | a warning rather than the installation.
         */
        if (! $this->env->markInstalled()) {
            Log::warning('Installer wrote the lock but could not set APP_INSTALLED in .env.');
        }

        $this->checkpoint->finish(Version::app());
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private function administratorRecord(InstallPlan $plan): User
    {
        $user = User::query()->where('email', $plan->administrator->email)->first();

        if ($user === null) {
            throw $this->failure(
                InstallStep::Workspace,
                __('The administrator account could not be found.'),
                __('Run the installation again from the administrator step.'),
            );
        }

        return $user;
    }

    private function administratorExists(InstallPlan $plan): bool
    {
        return User::query()
            ->where('email', $plan->administrator->email)
            ->where('is_admin', true)
            ->exists();
    }

    /**
     * Build the failure, and put the technical half of it somewhere only an administrator
     * with filesystem access can read.
     */
    private function failure(
        InstallStep $step,
        string $reason,
        string $suggestion,
        ?Throwable $previous = null,
    ): InstallationFailed {
        $reference = strtoupper(Str::random(8));

        Log::error('Planvio installation step failed.', [
            'reference' => $reference,
            'step' => $step->value,
            // Scrubbed before it is written: a driver exception is free to quote the DSN or
            // the credential it just rejected, and the log is still a file on this server.
            'exception' => $previous === null ? null : (new SecretScrubber)->scrub($previous->getMessage()),
            'exception_class' => $previous === null ? null : $previous::class,
        ]);

        return new InstallationFailed($step, $reason, $suggestion, $reference, $previous);
    }
}
