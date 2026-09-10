<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;

/**
 * Installs or removes the demo workspace.
 *
 * Demo data has exactly one entry point, and it is this command. Nothing seeds it
 * automatically: {@see DatabaseSeeder} calls only the default data, so
 * `migrate --seed` during an install or an upgrade can never conjure a fake workspace.
 *
 * In production the command refuses outright unless `--force` is passed. That is deliberately
 * blunt: an interactive confirmation is no protection at all in the deploy scripts and cron
 * entries where this would actually be run by accident.
 */
final class PlanvioDemoCommand extends Command
{
    protected $signature = 'planvio:demo
        {--remove : Remove previously installed demo data instead of installing it}
        {--force : Allow the command to run in production}';

    protected $description = 'Install or remove the Planvio demo workspace, users and projects';

    public function handle(Container $container): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error(__('Refusing to run in production. Pass --force if you really mean it.'));

            return self::FAILURE;
        }

        /** @var DemoDataSeeder $seeder */
        $seeder = $container->make(DemoDataSeeder::class);
        $seeder->setContainer($container)->setCommand($this);

        return $this->option('remove')
            ? $this->remove($seeder)
            : $this->install($seeder);
    }

    private function install(DemoDataSeeder $seeder): int
    {
        if ($seeder->installation() !== null) {
            $this->components->warn(__('Demo data is already installed. Run `php artisan planvio:demo --remove` first.'));

            return self::FAILURE;
        }

        $this->components->info(__('Installing demo data'));

        try {
            $seeder->run();
        } catch (RuntimeException $exception) {
            // The seeder raises these for the situations only a person can resolve — the
            // fixed demo addresses already belong to real accounts, or the workspace template
            // no longer has a column the demo asks for. The message says which, and repeating
            // it verbatim is more use than wrapping it in an apology.
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error(__('Demo data could not be installed: :message', [
                'message' => $exception->getMessage(),
            ]));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function remove(DemoDataSeeder $seeder): int
    {
        if ($seeder->installation() === null) {
            $this->components->warn(__('No demo data is installed.'));

            return self::SUCCESS;
        }

        if ($this->input->isInteractive() && ! $this->confirm(__('This permanently deletes the demo workspace and its three accounts. Continue?'), true)) {
            $this->components->warn(__('Nothing was removed.'));

            return self::SUCCESS;
        }

        try {
            $removed = $seeder->remove();
        } catch (Throwable $exception) {
            $this->components->error(__('Demo data could not be removed: :message', [
                'message' => $exception->getMessage(),
            ]));

            return self::FAILURE;
        }

        if (! $removed) {
            $this->components->warn(__('No demo data is installed.'));

            return self::SUCCESS;
        }

        $this->components->info(__('Demo data removed.'));

        return self::SUCCESS;
    }
}
