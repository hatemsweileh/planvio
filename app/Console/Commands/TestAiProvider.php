<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Providers\AiProviderException;
use App\Ai\Providers\ProviderFactory;
use App\Models\AiProvider;
use Illuminate\Console\Command;

/**
 * "Test connection", from a shell.
 *
 * The admin panel has the same button, but the moment you most need this is the moment the
 * panel is the hardest thing to reach: a fresh install behind a proxy, an endpoint that only
 * resolves from the server, a firewall rule somebody changed. One round trip and a sentence is
 * usually the whole diagnosis.
 *
 * ## Nothing about the credential is printed. Not even a fingerprint.
 *
 * The admin panel shows a masked key because it is rendered to one authenticated administrator
 * looking at a screen. A console command is different: its output goes wherever the invoker
 * sent it — a cron log, a support ticket, a screenshot pasted into a chat — and on shared
 * hosting those places are not private. So this reports only whether a key is configured
 * (CLAUDE.md rule 4).
 *
 * The provider drivers hold to the same rule from the other side: every
 * {@see AiProviderException} message is written to be safe to display and safe to log, which is
 * why the failure text below can be printed verbatim. An endpoint rejecting a credential is
 * exactly when it is most likely to echo that credential back, and the drivers never pass a
 * response body through.
 */
final class TestAiProvider extends Command
{
    protected $signature = 'ai:test-provider
        {provider : The provider id, or its name}';

    protected $description = 'Make one round trip to a configured AI provider and report whether it answered.';

    public function handle(ProviderFactory $factory): int
    {
        if (! (bool) config('ai.enabled', false)) {
            $this->components->warn(__('ai.console.ai_disabled_test'));
        }

        $provider = $this->resolve();

        if ($provider === null) {
            return self::FAILURE;
        }

        $this->components->twoColumnDetail(__('Provider'), (string) $provider->name);
        $this->components->twoColumnDetail(__('Driver'), $provider->driver?->value ?? '—');
        $this->components->twoColumnDetail(__('Model'), (string) ($provider->model ?? '—'));
        $this->components->twoColumnDetail(
            __('API key'),
            $provider->hasApiKey() ? __('ai.console.key_configured') : __('ai.console.key_absent'),
        );
        $this->components->twoColumnDetail(
            __('Active'),
            $provider->is_active ? __('Yes') : __('No'),
        );

        try {
            $health = $factory->make($provider)->testConnection();
        } catch (AiProviderException $exception) {
            // Safe to print by the driver's own contract: no key, no header, no request body.
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($health->latencyMs !== null) {
            $this->components->twoColumnDetail(__('Latency'), $health->latencyMs.' ms');
        }

        if ($health->model !== null) {
            $this->components->twoColumnDetail(__('Answered as'), $health->model);
        }

        if (! $health->ok) {
            $this->components->error($health->message);

            return self::FAILURE;
        }

        $this->components->info($health->message);

        return self::SUCCESS;
    }

    /**
     * The provider named on the command line, or null once the failure has been reported.
     *
     * Matching an id first and a name second is what makes both `ai:test-provider 3` and
     * `ai:test-provider "OpenAI"` work. An ambiguous name is refused rather than guessed:
     * testing the wrong endpoint and reporting it as healthy is worse than asking again.
     */
    private function resolve(): ?AiProvider
    {
        $argument = trim((string) $this->argument('provider'));

        if ($argument === '') {
            $this->components->error(__('ai.console.provider_required'));

            return null;
        }

        if (ctype_digit($argument)) {
            $provider = AiProvider::query()->find((int) $argument);

            if ($provider instanceof AiProvider) {
                return $provider;
            }
        }

        $matches = AiProvider::query()->where('name', $argument)->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() > 1) {
            $this->components->error(__('ai.console.provider_ambiguous', ['name' => $argument]));

            return null;
        }

        $this->components->error(__('ai.console.provider_not_found', ['name' => $argument]));
        $this->listProviders();

        return null;
    }

    private function listProviders(): void
    {
        $providers = AiProvider::query()->orderBy('id')->get();

        if ($providers->isEmpty()) {
            $this->components->warn(__('ai.console.no_providers'));

            return;
        }

        $this->table(
            [__('Id'), __('Name'), __('Driver'), __('Model'), __('Active')],
            $providers->map(static fn (AiProvider $provider): array => [
                (string) $provider->getKey(),
                (string) $provider->name,
                $provider->driver?->value ?? '—',
                (string) ($provider->model ?? '—'),
                $provider->is_active ? __('Yes') : __('No'),
            ])->all(),
        );
    }
}
