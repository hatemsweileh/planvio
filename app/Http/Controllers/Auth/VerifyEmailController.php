<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\WritesAuthAuditLog;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Email verification.
 *
 * The link is a signed, expiring URL carrying the account id and a hash of the address, so
 * changing the address after the link was issued invalidates it — verification proves that
 * the person controls *that* mailbox, not merely that they once did.
 *
 * Whether verification is enforced is a property of the User model: the `verified` middleware
 * only bites for a user that implements `MustVerifyEmail`. These routes work either way, so
 * an install with no working SMTP is never bricked by a verification wall it cannot pass.
 */
final class VerifyEmailController extends Controller
{
    use WritesAuthAuditLog;

    public function notice(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasVerifiedEmail()) {
            return redirect()->intended(route('home'));
        }

        return view('auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasVerifiedEmail()) {
            return redirect()->route('home')->with('status', __('Your email address is already verified.'));
        }

        $request->fulfill();

        if ($user instanceof User) {
            $this->audit($request, 'auth.email_verified', $user, __('Email address verified.'), [
                'email' => $user->email,
            ]);
        }

        return redirect()->route('home')->with('status', __('Your email address has been verified.'));
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', __('A new verification link has been sent to your email address.'));
    }
}
