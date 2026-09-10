<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\Providers\AiProviderException;
use App\Ai\Providers\ProviderFactory;
use App\Enums\AiDriver;

/**
 * A model endpoint Planvio can talk to (ARCHITECTURE.md section 7.2).
 *
 * Implementations are constructed exclusively by
 * {@see ProviderFactory}, which maps the `ai_providers.driver` enum to a
 * concrete class through a `match` expression. No implementation is ever resolved from a
 * class name stored in the database.
 *
 * Every method must fail closed: any transport, decoding or credential problem surfaces as
 * an {@see AiProviderException} whose message is safe to show a user and
 * safe to write to a log — never the Authorization header, the key, or the request body.
 */
interface AiProvider
{
    /**
     * The driver key this instance speaks, matching an {@see AiDriver} value.
     */
    public function key(): string;

    /**
     * Perform one completion. Supports tool calling where the driver does.
     *
     * @throws AiProviderException on any transport or protocol failure
     */
    public function chat(AiChatRequest $request): AiChatResponse;

    /**
     * Whether this endpoint can be given tools.
     *
     * A driver that reports false is run in assistant mode only: the agent loop must not
     * offer it tools, and the driver strips any it is handed rather than sending a payload
     * the endpoint would reject.
     */
    public function supportsTools(): bool;

    /**
     * A cheap round trip for the admin panel. Never throws: a failure is reported as an
     * unhealthy result carrying a translated, credential-free message.
     */
    public function testConnection(): ProviderHealth;
}
