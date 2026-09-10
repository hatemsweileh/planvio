<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The two ways a session becomes authenticated, and the half-authenticated state between
 * them.
 *
 * A correct password is not a session. When the account carries a confirmed second factor
 * the password only earns a short-lived note in the session saying which account is being
 * challenged — the guard stays empty until the code is verified, so an abandoned challenge
 * leaves nothing behind that could be resumed.
 *
 * Every transition regenerates the session id. The id a visitor arrived with must never be
 * the id they leave authenticated with, or an attacker who planted a cookie before sign-in
 * would still be holding a valid one afterwards.
 *
 * The host class must also use {@see WritesAuthAuditLog}.
 */
trait IssuesAuthenticatedSession
{
    /** Where the pending challenge lives while the second factor is outstanding. */
    protected const TWO_FACTOR_SESSION_KEY = 'auth.two_factor';

    /**
     * Long enough to find a phone, short enough that a challenge left open on a shared
     * machine expires before somebody else sits down at it.
     */
    protected const TWO_FACTOR_CHALLENGE_MINUTES = 10;

    /**
     * @param array<string, scalar|array<array-key, mixed>|null> $properties
     */
    protected function completeLogin(
        Request $request,
        User $user,
        bool $remember,
        array $properties = [],
    ): RedirectResponse {
        $this->forgetTwoFactorChallenge($request);

        Auth::login($user, $remember);

        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => Carbon::now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->audit(
            $request,
            'auth.login',
            $user,
            __('Signed in.'),
            $properties + ['remember' => $remember],
        );

        return redirect()->intended(route('home'));
    }

    protected function beginTwoFactorChallenge(Request $request, User $user, bool $remember): RedirectResponse
    {
        $request->session()->regenerate();

        $request->session()->put(self::TWO_FACTOR_SESSION_KEY, [
            'id' => $user->getKey(),
            'remember' => $remember,
            'expires_at' => Carbon::now()->addMinutes(self::TWO_FACTOR_CHALLENGE_MINUTES)->getTimestamp(),
        ]);

        return redirect()->route('two-factor.login');
    }

    /**
     * The account waiting on a second factor, or null when there is no live challenge.
     */
    protected function pendingTwoFactorUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::TWO_FACTOR_SESSION_KEY);

        if (! is_array($pending) || ! isset($pending['id'], $pending['expires_at'])) {
            return null;
        }

        if (! is_numeric($pending['expires_at']) || (int) $pending['expires_at'] < Carbon::now()->getTimestamp()) {
            $this->forgetTwoFactorChallenge($request);

            return null;
        }

        $user = User::query()->find($pending['id']);

        // A challenge outlives the reasons it was issued: the account may have been
        // deactivated, deleted, or had two-factor removed by an administrator since.
        if (! $user instanceof User || ! $user->is_active || ! $user->hasTwoFactorEnabled()) {
            $this->forgetTwoFactorChallenge($request);

            return null;
        }

        return $user;
    }

    protected function pendingTwoFactorRemember(Request $request): bool
    {
        $pending = $request->session()->get(self::TWO_FACTOR_SESSION_KEY);

        return is_array($pending) && filter_var($pending['remember'] ?? false, FILTER_VALIDATE_BOOL);
    }

    protected function forgetTwoFactorChallenge(Request $request): void
    {
        $request->session()->forget(self::TWO_FACTOR_SESSION_KEY);
    }
}
