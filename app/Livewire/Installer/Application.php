<?php

declare(strict_types=1);

namespace App\Livewire\Installer;

use App\Livewire\Installer\Concerns\WizardScreen;
use App\Services\Install\ApplicationSettings;
use App\Services\Install\EnvironmentDetector;
use App\Services\Install\WizardStep;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * What the installation calls itself, where it lives, and how it formats things.
 *
 * Every field arrives pre-filled from {@see EnvironmentDetector} and every field is editable
 * (spec §138). The URL is the one that matters: it is derived from the `Host` header, which
 * is not trustworthy on a server answering for several names, and it ends up in every
 * password-reset link Planvio ever sends. So it is offered as a suggestion, and validated on
 * submit as an absolute http(s) address rather than accepted because it was detected.
 */
final class Application extends Component
{
    use WizardScreen;

    public string $name = 'Planvio';

    public string $url = '';

    public string $timezone = 'UTC';

    public string $locale = 'en';

    public string $currency = 'USD';

    public string $dateFormat = 'Y-m-d';

    public string $environment = ApplicationSettings::ENVIRONMENT_PRODUCTION;

    /**
     * What the server told us about itself, shown beside the form so the administrator can
     * see what the defaults were derived from.
     *
     * @var array<string, string>
     */
    public array $detected = [];

    protected function step(): WizardStep
    {
        return WizardStep::Application;
    }

    public function mount(EnvironmentDetector $detector): void
    {
        if ($this->guardOrder()) {
            return;
        }

        $this->detected = $detector->summary();

        $saved = $this->state()->get(WizardStep::Application);

        $this->name = $this->stringOr($saved, 'name', $detector->appName());
        $this->url = $this->stringOr($saved, 'url', $detector->appUrl());
        $this->timezone = $this->stringOr($saved, 'timezone', $detector->timezone());
        $this->locale = $this->stringOr($saved, 'locale', $detector->locale());
        $this->currency = $this->stringOr($saved, 'currency', $detector->currency());
        $this->dateFormat = $this->stringOr($saved, 'date_format', $detector->dateFormat());
        $this->environment = $this->stringOr($saved, 'environment', $detector->environment());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'url' => ['required', 'string', 'max:191', 'url:http,https'],
            'timezone' => ['required', 'string', Rule::in($this->timezones())],
            'locale' => ['required', 'string', 'max:8', 'regex:/^[a-z]{2}(_[A-Za-z]{2,4})?$/'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'dateFormat' => ['required', 'string', Rule::in(array_keys(ApplicationSettings::dateFormats()))],
            'environment' => ['required', Rule::in([
                ApplicationSettings::ENVIRONMENT_PRODUCTION,
                ApplicationSettings::ENVIRONMENT_LOCAL,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => __('application name'),
            'url' => __('application address'),
            'dateFormat' => __('date format'),
        ];
    }

    public function save(): void
    {
        $this->validate();

        $this->advance([
            'name' => trim($this->name),
            'url' => rtrim(trim($this->url), '/'),
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'currency' => strtoupper($this->currency),
            'date_format' => $this->dateFormat,
            'environment' => $this->environment,
        ]);
    }

    public function back(): void
    {
        $this->goBack();
    }

    /**
     * @return list<string>
     */
    public function timezones(): array
    {
        return timezone_identifiers_list();
    }

    /**
     * @return array<string, string>
     */
    public function dateFormats(): array
    {
        return ApplicationSettings::dateFormats();
    }

    public function render(): View
    {
        return $this->screen(
            'installer.steps.application',
            __('Name your installation'),
            __('Planvio has filled these in from the address you opened. Correct anything that is wrong — the address in particular, because password reset links are built from it.'),
        );
    }

    /**
     * @param array<string, mixed> $saved
     */
    private function stringOr(array $saved, string $key, string $fallback): string
    {
        $value = $saved[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
