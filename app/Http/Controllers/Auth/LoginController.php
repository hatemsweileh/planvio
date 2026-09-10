<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\IssuesAuthenticatedSession;
use App\Http\Controllers\Auth\Concerns\WritesAuthAuditLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Password sign-in.
 *
 * Credentials are checked against the model directly rather than through `Auth::attempt()`,
 * because an account with a confirmed second factor must not reach the guard at all: logging
 * somebody in and then logging them straight back out would leave a real authenticated
 * session in the store for the length of one request.
 *
 * Every outcome — success, wrong password, unknown address, deactivated account — leaves an
 * `audit_logs` row. A sign-in trail with only the successes in it answers none of the
 * questions it gets asked.
 */
final class LoginController extends Controller
{
    use IssuesAuthenticatedSession;
    use WritesAuthAuditLog;

    public function __construct(private readonly Settings $settings) {}

    public function create(): View
    {
        return view('auth.login', [
            'canRegister' => RegisterController::isEnabled($this->settings),
        ]);
    }

    /**
     * @throws ValidationException on a bad credential, a deactivated account, or a lockout
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->ensureIsNotRateLimited();

        $credentials = $request->credentials();

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user instanceof User) {
            // Equalise the cost of an unknown address with that of a wrong password:
            // returning without hashing anything makes "no such account" measurably
            // faster, which is an account oracle wearing a stopwatch.
            Hash::make($credentials['password']);

            $this->reject($request, null, 'unknown_account', $credentials['email']);
        }

        if (! Hash::check($credentials['password'], (string) $user->password)) {
            $this->reject($request, $user, 'invalid_password', $credentials['email']);
        }

        if (! $user->is_active) {
            $request->hitRateLimiter();

            $this->audit(
                $request,
                'auth.login_failed',
                $user,
                __('Sign-in refused: the account is deactivated.'),
                ['email' => $credentials['email'], 'reason' => 'inactive_account'],
            );

            throw ValidationException::withMessages([
                'email' => __('This account has been deactivated. Contact an administrator.'),
            ]);
        }

        // The work factor may have been raised since this password was last set.
        if (Hash::needsRehash((string) $user->password)) {
            $user->forceFill(['password' => $credentials['password']])->save();
        }

        $request->clearRateLimiter();

        if ($user->hasTwoFactorEnabled()) {
            return $this->beginTwoFactorChallenge($request, $user, $request->remember());
        }

        return $this->completeLogin($request, $user, $request->remember(), ['two_factor' => false]);
    }

    /**
     * @throws ValidationException always
     */
    private function reject(LoginRequest $request, ?User $user, string $reason, string $email): never
    {
        $request->hitRateLimiter();

        $this->audit(
            $request,
            'auth.login_failed',
            $user,
            __('Failed sign-in attempt.'),
            ['email' => $email, 'reason' => $reason],
        );

        // One message for both reasons. Telling the visitor which half was wrong tells them
        // whether the address is registered.
        throw ValidationException::withMessages(['email' => __('auth.failed')]);
    }
}
