<?php

declare(strict_types=1);

namespace App\Services\Install;

/**
 * The nine screens of the wizard, in order.
 *
 * Distinct from {@see InstallStep}, and deliberately so: this enum is what the customer walks
 * through and what the step rail draws, while `InstallStep` is what the engine executes once
 * they press Install. Conflating them would put "Run migrations" in the navigation.
 */
enum WizardStep: string
{
    case Welcome = 'welcome';
    case Requirements = 'requirements';
    case Database = 'database';
    case Application = 'application';
    case Administrator = 'administrator';
    case Email = 'email';
    case Ai = 'ai';
    case Install = 'install';
    case Finish = 'finish';

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
            self::Welcome => __('Welcome'),
            self::Requirements => __('Requirements'),
            self::Database => __('Database'),
            self::Application => __('Application'),
            self::Administrator => __('Administrator'),
            self::Email => __('Email'),
            self::Ai => __('AI'),
            self::Install => __('Install'),
            self::Finish => __('Finish'),
        };
    }

    public function routeName(): string
    {
        return 'install.'.$this->value;
    }

    public function url(): string
    {
        return route($this->routeName());
    }

    public function position(): int
    {
        return array_search($this, self::sequence(), true) + 1;
    }

    public function previous(): ?self
    {
        $sequence = self::sequence();
        $index = array_search($this, $sequence, true);

        return $index > 0 ? $sequence[$index - 1] : null;
    }

    public function next(): ?self
    {
        $sequence = self::sequence();
        $index = array_search($this, $sequence, true);

        return $sequence[$index + 1] ?? null;
    }

    public function isBefore(self $other): bool
    {
        return $this->position() < $other->position();
    }

    /**
     * Whether this screen collects something the installer needs.
     *
     * Welcome and Requirements read; Install and Finish report. The four in between are the
     * ones the state store expects to find data for before it will build a plan.
     */
    public function collectsData(): bool
    {
        return in_array($this, [self::Database, self::Application, self::Administrator, self::Email, self::Ai], true);
    }

    /**
     * The install step that has to run again when this screen's answers change.
     *
     * Everything but the administrator's own details ends up in `.env`, so editing any of it
     * invalidates the file that was written from the old values — which is why the default is
     * the step that writes it rather than the step that happens to share the screen's name.
     */
    public function rewindTarget(): InstallStep
    {
        return match ($this) {
            self::Administrator => InstallStep::Administrator,
            default => InstallStep::EnvFile,
        };
    }
}
