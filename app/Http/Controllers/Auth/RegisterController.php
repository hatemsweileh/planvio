<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\IssuesAuthenticatedSession;
use App\Http\Controllers\Auth\Concerns\WritesAuthAuditLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Self-service registration, off unless an administrator turns it on.
 *
 * A self-hosted install is normally a closed team: people arrive through an invitation, and
 * an open sign-up form on a server somebody put on the internet is a way to collect
 * strangers, not colleagues. The default is therefore closed, and both the form and its
 * handler answer 404 while it is — the same answer a route that does not exist would give,
 * because a 403 would still confirm the feature is there.
 */
final class RegisterController extends Controller
{
    use IssuesAuthenticatedSession;
    use WritesAuthAuditLog;

    public const SETTING_KEY = 'auth.registration_enabled';

    public function __construct(private readonly Settings $settings) {}

    public static function isEnabled(Settings $settings): bool
    {
        return filter_var($settings->get(self::SETTING_KEY, false), FILTER_VALIDATE_BOOL);
    }

    public function create(): View
    {
        $this->ensureEnabled();

        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $this->ensureEnabled();

        $user = User::query()->create([
            'name' => (string) $request->string('name'),
            'email' => (string) $request->string('email'),
            'password' => (string) $request->string('password'),
            'timezone' => (string) config('planvio.defaults.workspace.timezone', 'UTC'),
            'locale' => (string) config('planvio.defaults.workspace.locale', 'en'),
        ]);

        event(new Registered($user));

        $this->audit(
            $request,
            'auth.register',
            $user,
            __('Account created through self-service registration.'),
            ['email' => $user->email],
        );

        return $this->completeLogin($request, $user, false, [
            'two_factor' => false,
            'via' => 'registration',
        ]);
    }

    private function ensureEnabled(): void
    {
        if (! self::isEnabled($this->settings)) {
            abort(404);
        }
    }
}
