<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Middleware\ApiWorkspace;
use App\Models\User;
use App\Models\Workspace;
use Tests\TestCase;

/**
 * Shared setup for the API suite.
 *
 * Every test here authenticates with a *real* Sanctum token rather than
 * `Sanctum::actingAs()`. The difference matters: `actingAs` installs a transient token and
 * skips the guard, so a suite built on it would pass with the `auth:sanctum` middleware
 * removed from the route file. Minting the token and sending the `Authorization` header
 * exercises the thing the API's security actually rests on.
 */
abstract class ApiTestCase extends TestCase
{
    /**
     * Authenticate as $user, optionally naming a workspace in the header.
     */
    protected function asToken(User $user, ?Workspace $workspace = null, string $name = 'test-token'): static
    {
        // A test process makes several requests against one container and Sanctum's guard
        // memoises whoever it resolved first, so without this a test that switched actors
        // would keep making requests as the previous one — and would pass every permission
        // assertion for entirely the wrong reason. In production each request is a new
        // process and there is nothing to forget.
        $this->forgetAuthentication();

        // `withHeaders()` merges into the client's defaults and never clears them, so without
        // this a request made after one that named a workspace would still be carrying that
        // header — and a test asserting "no header means 400" would pass a header.
        $this->flushHeaders();

        $headers = [
            'Authorization' => 'Bearer '.$user->createToken($name)->plainTextToken,
            'Accept' => 'application/json',
        ];

        if ($workspace instanceof Workspace) {
            $headers[ApiWorkspace::HEADER] = (string) $workspace->slug;
        }

        return $this->withHeaders($headers);
    }

    /**
     * The tenant header on its own, for a request that is already authenticated.
     *
     * @return array<string, string>
     */
    protected function workspaceHeader(Workspace $workspace): array
    {
        return [ApiWorkspace::HEADER => (string) $workspace->slug];
    }

    /**
     * Throw away the resolved guard so the next request authenticates from scratch.
     *
     * A test process makes several requests against one container, and Sanctum's guard
     * memoises the user it resolved. In production every request is a new process and there
     * is nothing to memoise, so this is a harness artefact — but a test that revokes a token
     * or deactivates an account and then asserts the next call fails is testing exactly the
     * thing the memo would hide.
     */
    protected function forgetAuthentication(): void
    {
        $this->app?->make('auth')->forgetGuards();
    }
}
