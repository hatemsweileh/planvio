<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\WritesAuthAuditLog;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sign-out.
 *
 * The guard is cleared *and* the session is destroyed. Clearing only the guard would leave
 * the same session id valid and its contents — flash data, the last workspace, any pending
 * challenge — readable by whoever holds the cookie next. A fresh CSRF token is issued so the
 * sign-in form the user lands on is not carrying the token of a session that no longer
 * exists.
 */
final class LogoutController extends Controller
{
    use WritesAuthAuditLog;

    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user instanceof User) {
            $this->audit($request, 'auth.logout', $user, __('Signed out.'));
        }

        return redirect()->route('login');
    }
}
