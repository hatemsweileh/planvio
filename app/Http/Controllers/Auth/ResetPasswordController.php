<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\WritesAuthAuditLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Choosing a new password from a reset link.
 *
 * Every failure — expired token, wrong token, address that belongs to nobody — comes back as
 * the same "this link is no longer valid" message, for the same reason the request form has
 * one answer: the broker's `passwords.user` line would otherwise confirm which addresses are
 * registered.
 *
 * A successful reset ends every other session the account had. The person resetting is often
 * doing it precisely because somebody else may have their old password, and leaving that
 * somebody signed in would defeat the exercise.
 */
final class ResetPasswordController extends Controller
{
    use WritesAuthAuditLog;

    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->string('email'),
        ]);
    }

    /**
     * @throws ValidationException when the token or address no longer resolves
     */
    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        $reset = null;

        $status = Password::broker()->reset(
            [
                'email' => (string) $request->string('email'),
                'password' => (string) $request->string('password'),
                'password_confirmation' => (string) $request->string('password_confirmation'),
                'token' => (string) $request->string('token'),
                'is_active' => true,
            ],
            function (User $user, string $password) use (&$reset): void {
                $user->forceFill([
                    'password' => $password,
                    // Retires every "remember me" cookie ever issued to this account.
                    'remember_token' => Str::random(60),
                ])->save();

                $reset = $user;

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET || ! $reset instanceof User) {
            $this->audit(
                $request,
                'auth.password_reset_failed',
                null,
                __('Password reset link rejected.'),
                ['email' => (string) $request->string('email'), 'outcome' => $status],
            );

            throw ValidationException::withMessages(['email' => __('passwords.token')]);
        }

        $this->forgetOtherSessions($reset);

        $this->audit($request, 'auth.password_reset', $reset, __('Password reset completed.'), [
            'email' => $reset->email,
        ]);

        return redirect()->route('login')->with('status', __($status));
    }

    /**
     * Drop every stored session belonging to the account.
     *
     * Only meaningful for the database session driver, which is what a Planvio install runs:
     * file and cookie sessions carry no owner to match on.
     */
    private function forgetOtherSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $table = config('session.table', 'sessions');

        if (! is_string($table) || $table === '') {
            return;
        }

        DB::table($table)->where('user_id', $user->getKey())->delete();
    }
}
