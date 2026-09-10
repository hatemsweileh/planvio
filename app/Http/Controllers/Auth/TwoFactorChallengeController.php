<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\IssuesAuthenticatedSession;
use App\Http\Controllers\Auth\Concerns\WritesAuthAuditLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The second factor, between a correct password and a session.
 *
 * The challenge has its own rate limit, keyed on the account being challenged and the client
 * address. Six digits is a million combinations; without a limit here the password limit
 * would be the only thing standing between a stolen password and an account, and it has
 * already been satisfied by the time this screen appears.
 *
 * A recovery code is spent the moment it verifies, whether or not the sign-in that follows
 * succeeds — a code that survived a failed attempt would be a code an observer could replay.
 */
final class TwoFactorChallengeController extends Controller
{
    use IssuesAuthenticatedSession;
    use WritesAuthAuditLog;

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function create(Request $request): View|RedirectResponse
    {
        $user = $this->pendingTwoFactorUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge', [
            'hasRecoveryCodes' => $this->twoFactor->recoveryCodes($user) !== [],
        ]);
    }

    /**
     * @throws ValidationException on a wrong code or a lockout
     */
    public function store(TwoFactorChallengeRequest $request): RedirectResponse
    {
        $user = $this->pendingTwoFactorUser($request);

        if ($user === null) {
            return redirect()->route('login')->withErrors([
                'email' => __('Your sign-in attempt expired. Please sign in again.'),
            ]);
        }

        $key = $this->throttleKey($request, $user);

        if (RateLimiter::tooManyAttempts($key, $this->maxAttempts())) {
            $seconds = RateLimiter::availableIn($key);

            $this->audit($request, 'auth.two_factor_throttled', $user, __('Two-factor challenge rate limited.'));

            throw ValidationException::withMessages([
                'code' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            ]);
        }

        $recoveryCode = $request->recoveryCode();

        if ($recoveryCode !== null) {
            return $this->attemptRecoveryCode($request, $user, $recoveryCode, $key);
        }

        return $this->attemptCode($request, $user, (string) $request->code(), $key);
    }

    /**
     * @throws ValidationException
     */
    private function attemptCode(
        TwoFactorChallengeRequest $request,
        User $user,
        string $code,
        string $key,
    ): RedirectResponse {
        $secret = $user->two_factor_secret;

        if (! is_string($secret) || ! $this->twoFactor->verify($secret, $code)) {
            RateLimiter::hit($key, $this->decayMinutes() * 60);

            $this->audit($request, 'auth.two_factor_failed', $user, __('Two-factor code rejected.'), [
                'method' => 'totp',
            ]);

            throw ValidationException::withMessages([
                'code' => __('That authentication code is not valid.'),
            ]);
        }

        RateLimiter::clear($key);

        return $this->completeLogin($request, $user, $this->pendingTwoFactorRemember($request), [
            'two_factor' => true,
            'method' => 'totp',
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function attemptRecoveryCode(
        TwoFactorChallengeRequest $request,
        User $user,
        string $code,
        string $key,
    ): RedirectResponse {
        if (! $this->twoFactor->consumeRecoveryCode($user, $code)) {
            RateLimiter::hit($key, $this->decayMinutes() * 60);

            $this->audit($request, 'auth.two_factor_failed', $user, __('Recovery code rejected.'), [
                'method' => 'recovery_code',
            ]);

            throw ValidationException::withMessages([
                'recovery_code' => __('That recovery code is not valid.'),
            ]);
        }

        RateLimiter::clear($key);

        $remaining = count($this->twoFactor->recoveryCodes($user));

        $this->audit(
            $request,
            'auth.two_factor_recovery_code_used',
            $user,
            __('Signed in with a recovery code.'),
            ['remaining' => $remaining],
        );

        return $this->completeLogin($request, $user, $this->pendingTwoFactorRemember($request), [
            'two_factor' => true,
            'method' => 'recovery_code',
        ]);
    }

    private function throttleKey(Request $request, User $user): string
    {
        return 'two-factor|'.$user->getKey().'|'.$request->ip();
    }

    private function maxAttempts(): int
    {
        $value = config('planvio.security.login.max_attempts', 5);

        return max(1, is_numeric($value) ? (int) $value : 5);
    }

    private function decayMinutes(): int
    {
        $value = config('planvio.security.login.decay_minutes', 5);

        return max(1, is_numeric($value) ? (int) $value : 5);
    }
}
