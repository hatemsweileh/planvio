<?php

declare(strict_types=1);

namespace App\Livewire\Installer;

use App\Enums\AiDriver;
use App\Enums\AiMode;
use App\Livewire\Installer\Concerns\WizardScreen;
use App\Services\Install\AiCredentials;
use App\Services\Install\AiTester;
use App\Services\Install\WizardStep;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * The optional AI step (spec §72).
 *
 * Off is the default and a complete answer. Planvio installs, runs and is useful with AI
 * switched off; nothing later in the wizard or in the product assumes otherwise.
 *
 * The key is write-only from here on. Once it has been stored, revisiting this screen shows
 * that a key is saved but never its value: {@see self::$hasStoredKey} is what the field
 * reports, and leaving the input blank keeps whatever is already held rather than clearing
 * it. Nothing sends the key back to the browser after it has been saved.
 */
final class Ai extends Component
{
    use WizardScreen;

    public bool $enabled = false;

    public string $driver = 'openai';

    public string $baseUrl = '';

    public string $apiKey = '';

    public string $model = '';

    public string $mode = 'assistant';

    /**
     * Whether a key is already held for this installation, without saying what it is.
     */
    public bool $hasStoredKey = false;

    /**
     * @var array{status: string, message: string, detail: string|null, reference: string|null}|array{}
     */
    public array $result = [];

    protected function step(): WizardStep
    {
        return WizardStep::Ai;
    }

    public function mount(): void
    {
        if ($this->guardOrder()) {
            return;
        }

        $saved = $this->state()->get(WizardStep::Ai);

        if ($saved === []) {
            $this->applyDriverDefaults();

            return;
        }

        $this->enabled = (bool) ($saved['enabled'] ?? false);
        $this->driver = $this->stringOr($saved, 'driver', AiDriver::OpenAi->value);
        $this->baseUrl = $this->stringOr($saved, 'base_url', '');
        $this->model = $this->stringOr($saved, 'model', '');
        $this->mode = $this->stringOr($saved, 'mode', AiMode::Assistant->value);
        $this->hasStoredKey = is_string($saved['api_key'] ?? null) && $saved['api_key'] !== '';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'driver' => ['required', Rule::in(array_column(AiDriver::cases(), 'value'))],
            'baseUrl' => [
                $this->requiresBaseUrl() ? 'required' : 'nullable',
                'nullable',
                'string',
                'max:191',
                'url:http,https',
            ],
            'apiKey' => ['nullable', 'string', 'max:400'],
            'model' => [$this->enabled ? 'required' : 'nullable', 'nullable', 'string', 'max:191'],
            'mode' => ['required', Rule::in(array_column(AiMode::cases(), 'value'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'baseUrl' => __('base URL'),
            'apiKey' => __('API key'),
            'model' => __('model'),
            'mode' => __('default mode'),
        ];
    }

    /**
     * Switching provider re-suggests that provider's endpoint and model rather than leaving
     * OpenAI's defaults sitting under an Anthropic selection.
     */
    public function updatedDriver(): void
    {
        $this->result = [];
        $this->applyDriverDefaults();
    }

    public function updated(string $property): void
    {
        if ($property !== 'driver') {
            $this->result = [];
        }
    }

    public function testConnection(AiTester $tester): void
    {
        $this->validate();

        $this->result = $tester->test($this->credentials())->toArray();
    }

    public function save(): void
    {
        if (! $this->enabled) {
            $this->skip();

            return;
        }

        $this->validate();

        $data = [
            'enabled' => true,
            'driver' => $this->driver,
            'base_url' => trim($this->baseUrl),
            'model' => trim($this->model),
            'mode' => $this->mode,
        ];

        // A blank field on a revisit means "leave the stored key alone", not "remove it".
        $existing = $this->state()->get(WizardStep::Ai)['api_key'] ?? null;
        $key = trim($this->apiKey) !== '' ? trim($this->apiKey) : (is_string($existing) ? $existing : '');

        $this->advance([...$data, 'api_key' => $key]);
    }

    public function skip(): void
    {
        $this->advance(['enabled' => false]);
    }

    public function back(): void
    {
        $this->goBack();
    }

    public function requiresBaseUrl(): bool
    {
        return $this->enabled && (bool) config('ai.drivers.'.$this->driver.'.requires_base_url', false);
    }

    /**
     * @return array<string, string>
     */
    public function drivers(): array
    {
        $options = [];

        foreach (AiDriver::cases() as $driver) {
            $label = config('ai.drivers.'.$driver->value.'.label');
            $options[$driver->value] = is_string($label) ? $label : $driver->value;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function modes(): array
    {
        $options = [];

        foreach (AiMode::cases() as $mode) {
            $options[$mode->value] = $mode->label();
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public function suggestedModels(): array
    {
        $models = config('ai.drivers.'.$this->driver.'.suggested_models', []);

        return is_array($models) ? array_values(array_filter($models, is_string(...))) : [];
    }

    public function render(): View
    {
        return $this->screen(
            'installer.steps.ai',
            __('Connect an AI provider'),
            __('Optional. Planvio works fully without it, and you can turn it on later from the admin panel. Your key is stored encrypted in the database and never written to a file.'),
        );
    }

    private function applyDriverDefaults(): void
    {
        $baseUrl = config('ai.drivers.'.$this->driver.'.base_url');
        $model = config('ai.drivers.'.$this->driver.'.default_model');

        $this->baseUrl = is_string($baseUrl) ? $baseUrl : '';
        $this->model = is_string($model) ? $model : '';
    }

    private function credentials(): AiCredentials
    {
        $stored = $this->state()->get(WizardStep::Ai)['api_key'] ?? null;
        $key = trim($this->apiKey) !== '' ? trim($this->apiKey) : (is_string($stored) ? $stored : null);

        return AiCredentials::enabled(
            driver: AiDriver::tryFrom($this->driver) ?? AiDriver::OpenAi,
            baseUrl: trim($this->baseUrl) !== '' ? trim($this->baseUrl) : null,
            apiKey: $key,
            model: trim($this->model) !== '' ? trim($this->model) : null,
            mode: AiMode::tryFrom($this->mode) ?? AiMode::Assistant,
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
