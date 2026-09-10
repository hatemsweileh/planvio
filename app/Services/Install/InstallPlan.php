<?php

declare(strict_types=1);

namespace App\Services\Install;

/**
 * Everything the wizard collected, typed, in one object.
 *
 * The Livewire steps write loose arrays into the session; {@see InstallState} turns them into
 * this. That boundary is the point: by the time the install engine sees the data it is either
 * a complete, well-typed plan or it does not exist, so no step has to re-check whether the
 * port is really an integer or whether the mail settings were skipped.
 */
final readonly class InstallPlan
{
    public function __construct(
        public DatabaseCredentials $database,
        public ApplicationSettings $application,
        public AdministratorAccount $administrator,
        public MailCredentials $mail,
        public AiCredentials $ai,
    ) {}

    /**
     * The complete `.env` the installation should end up with.
     *
     * Assembled here rather than in the writer so that one method describes the finished
     * configuration in full, including the parts nobody was asked about: database-backed
     * session, cache and queue, which is how Planvio runs without Redis or a daemon
     * (ARCHITECTURE.md §9).
     *
     * @return array<string, string>
     */
    public function environmentValues(string $appKey): array
    {
        return [
            'APP_KEY' => $appKey,
            ...$this->application->toEnv(),
            ...$this->database->toEnv(),
            ...$this->mail->toEnv(),
            'SESSION_DRIVER' => 'database',
            'CACHE_STORE' => 'database',
            'QUEUE_CONNECTION' => 'database',
            'FILESYSTEM_DISK' => 'local',
            // The master switch only. Provider credentials live in `ai_providers`, encrypted
            // at rest, and never in a file the web server could be tricked into serving.
            'AI_ENABLED' => $this->ai->enabled ? 'true' : 'false',
            /*
             | False, and flipped by {@see Installer::lock()} once the lock has been written.
             |
             | This file is written at step two of twelve, and `EnsureInstalled::isInstalled()`
             | treats the flag as an answer. Writing `true` here told the whole application it
             | was installed with ten steps still to run: InstallerEnvironment stopped pinning
             | the wizard to the file session driver and handed the next request to a
             | `sessions` table the migrations had not created yet, and EnsureNotInstalled
             | began refusing the installer's own screens. The wizard could not get past
             | "Checking the database".
             */
            'APP_INSTALLED' => 'false',
        ];
    }
}
