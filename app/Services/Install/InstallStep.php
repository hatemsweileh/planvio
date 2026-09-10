<?php

declare(strict_types=1);

namespace App\Services\Install;

/**
 * The twelve things the installer does, in the order it does them (spec §73).
 *
 * The order is a dependency chain, not a preference: `.env` is written before the database is
 * touched so a crash mid-migration still leaves a configured application to come back to;
 * migrations precede the seeder; the administrator exists before the workspace they own; the
 * configuration cache is built from the finished `.env`; and the lock is last, because
 * writing it is the act that closes the wizard.
 *
 * Each case is also a checkpoint key. A retry after a failure re-runs from the failed step
 * rather than from the beginning, which is what keeps a half-migrated database from being
 * migrated twice (spec §139).
 */
enum InstallStep: string
{
    case Environment = 'environment';
    case EnvFile = 'env_file';
    case Database = 'database';
    case Migrations = 'migrations';
    case Seed = 'seed';
    case Administrator = 'administrator';
    case Workspace = 'workspace';
    case Ai = 'ai';
    case Storage = 'storage';
    case Optimize = 'optimize';
    case Verify = 'verify';
    case Lock = 'lock';

    /**
     * @return list<self>
     */
    public static function sequence(): array
    {
        return self::cases();
    }

    public function label(): string
    {
        return match ($this) {
            self::Environment => __('Preparing the application'),
            self::EnvFile => __('Writing the configuration file'),
            self::Database => __('Checking the database'),
            self::Migrations => __('Creating the database tables'),
            self::Seed => __('Adding the default data'),
            self::Administrator => __('Creating your administrator account'),
            self::Workspace => __('Creating your first workspace'),
            self::Ai => __('Saving the AI configuration'),
            self::Storage => __('Preparing the file storage'),
            self::Optimize => __('Caching the configuration'),
            self::Verify => __('Verifying the installation'),
            self::Lock => __('Locking the installer'),
        };
    }

    public function detail(): string
    {
        return match ($this) {
            self::Environment => __('Generating the application key and applying your settings.'),
            self::EnvFile => __('Saving your settings to .env so Planvio can start on its own.'),
            self::Database => __('Confirming Planvio can reach the database you chose.'),
            self::Migrations => __('Building the tables Planvio stores your work in.'),
            self::Seed => __('Project templates, platform settings and AI defaults.'),
            self::Administrator => __('The account you will sign in with.'),
            self::Workspace => __('With its statuses, tags and AI settings.'),
            self::Ai => __('Storing the provider, encrypted, or leaving AI switched off.'),
            self::Storage => __('Directories for uploads, and the public link for avatars.'),
            self::Optimize => __('Making Planvio start faster on every request.'),
            self::Verify => __('Reading everything back to be sure it is really there.'),
            self::Lock => __('Nobody can run this wizard again after this point.'),
        };
    }

    /**
     * 1-based position, for "step 4 of 12".
     */
    public function position(): int
    {
        return array_search($this, self::sequence(), true) + 1;
    }

    public static function count(): int
    {
        return count(self::sequence());
    }

    /**
     * The screen to send somebody back to when this step is the one that failed.
     *
     * "Change my settings" has to land on the field that is actually wrong. A migration that
     * failed is a database problem, not a reason to re-read the welcome screen; a workspace
     * that could not be created points at the account that owns it.
     */
    public function wizardStep(): WizardStep
    {
        return match ($this) {
            self::Database, self::Migrations, self::Seed => WizardStep::Database,
            self::Administrator, self::Workspace => WizardStep::Administrator,
            self::Ai => WizardStep::Ai,
            default => WizardStep::Application,
        };
    }
}
