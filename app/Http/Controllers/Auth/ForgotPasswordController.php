<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\WritesAuthAuditLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordResetLinkRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * "Send me a reset link".
 *
 * The response is byte-for-byte the same whether the address belongs to an account, belongs
 * to a deactivated account, or belongs to nobody at all — one message, no validation error,
 * no difference in redirect. Anything else turns this form into a way to test whether a
 * given person uses this installation, which is exactly the reconnaissance that precedes
 * credential stuffing.
 *
 * What actually happened is recorded in `audit_logs`, where only an administrator can read
 * it. The broker's own throttle (`config('auth.passwords.users.throttle')`) still applies
 * and is likewise invisible from outside.
 */
final class ForgotPasswordController extends Controller
{
    use WritesAuthAuditLog;

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(PasswordResetLinkRequest $request): RedirectResponse
    {
        $email = (string) $request->string('email');

        // Deactivated accounts are excluded here rather than after the fact: a reset link
        // for an account that cannot sign in is only useful to somebody who should not have
        // it. The visitor sees the same answer either way.
        $status = Password::broker()->sendResetLink([
            'email' => $email,
            'is_active' => true,
        ]);

        $this->audit(
            $request,
            'auth.password_reset_requested',
            User::query()->where('email', $email)->first(),
            __('Password reset link requested.'),
            ['email' => $email, 'outcome' => $status],
        );

        return back()->with('status', __(
            'If that address belongs to a Planvio account, a password reset link is on its way.',
        ));
    }
}
