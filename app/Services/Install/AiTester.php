<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Ai\Providers\AiProviderException;
use App\Ai\Providers\ProviderFactory;
use App\Models\AiProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Test AI connection", using the same factory the product uses.
 *
 * The provider is built from an **unsaved** `AiProvider` model. That is the point: the
 * installer must be able to tell an administrator that a key is wrong without first writing
 * it to the database, and the model carries the encrypted cast, so the key is handled exactly
 * as it will be in production rather than through a parallel code path that might not be.
 *
 * `ProviderHealth::message` is already written to be shown to a human and already free of
 * credentials ({@see AiProviderException}), so it is passed through unchanged.
 */
final class AiTester
{
    public function __construct(private readonly ProviderFactory $providers) {}

    public function test(AiCredentials $credentials): ConnectionTest
    {
        if (! $credentials->enabled) {
            return ConnectionTest::warned(__('AI is switched off, so there is nothing to test.'));
        }

        if ($credentials->requiresBaseUrl() && $credentials->resolvedBaseUrl() === null) {
            return ConnectionTest::failed(
                __('This provider needs the address of the endpoint to call.'),
                __('Enter the full base URL, for example https://openrouter.ai/api/v1 or http://127.0.0.1:11434/v1.'),
            );
        }

        if ($credentials->resolvedModel() === null) {
            return ConnectionTest::failed(
                __('Enter the model Planvio should ask for.'),
                __('The model name has to match one your provider exposes, for example gpt-4o-mini or claude-sonnet-4-5.'),
            );
        }

        try {
            $health = $this->providers->make($this->draft($credentials))->testConnection();
        } catch (AiProviderException $e) {
            // Already translated, already redacted: this exception type exists precisely so
            // that provider failures can be shown without laundering them again.
            return ConnectionTest::failed($e->getMessage());
        } catch (Throwable $e) {
            $reference = strtoupper(Str::random(8));

            Log::error('Installer AI connection test failed.', [
                'reference' => $reference,
                'exception' => $e::class,
            ]);

            return ConnectionTest::failed(
                __('Planvio could not complete the request to the AI provider.'),
                __('Check the base URL and that this server is allowed to make outgoing HTTPS requests. The technical detail was written to storage/logs.'),
                $reference,
            );
        }

        if (! $health->ok) {
            return ConnectionTest::failed($health->message);
        }

        return ConnectionTest::passed(
            $health->message,
            $health->model === null
                ? null
                : __('Answered as :model.', ['model' => $health->model]),
        );
    }

    /**
     * An in-memory `ai_providers` row. Never saved; the install step writes the real one.
     */
    private function draft(AiCredentials $credentials): AiProvider
    {
        $provider = new AiProvider;

        $provider->forceFill([
            'name' => $credentials->providerName(),
            'driver' => $credentials->driver,
            'base_url' => $credentials->resolvedBaseUrl(),
            'model' => $credentials->resolvedModel(),
            'timeout_seconds' => (int) config('ai.limits.request_timeout_seconds', 60),
            'is_active' => true,
            'is_default' => true,
        ]);

        // Assigned after forceFill so the encrypted cast runs over it, the way a save would.
        $provider->api_key = $credentials->apiKey;

        return $provider;
    }
}
