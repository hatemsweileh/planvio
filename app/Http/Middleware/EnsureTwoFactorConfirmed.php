<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use App\Support\TwoFactorRequirement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a user at two-factor enrolment when their role requires it.
 *
 * Sign-in already challenges anybody who *has* two-factor confirmed; this is the other half,
 * for installations that make it mandatory. Somebody promoted to owner overnight arrives the
 * next morning with a password and nothing else, so the gate has to be at the door rather
 * than on the settings page they may never open.
 *
 * Enrolment, sign-out and email verification stay reachable — a gate that blocks the screen
 * you need in order to pass it is a lockout, not a control.
 */
final class EnsureTwoFactorConfirmed
{
    /**
     * @var list<string>
     */
    private const ALWAYS_REACHABLE = [
        'two-factor.*',
        'logout',
        'verification.*',
    ];

    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User
            || $user->hasTwoFactorEnabled()
            || $request->routeIs(self::ALWAYS_REACHABLE)
            || ! self::isRequiredFor($user, $this->current->get())) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, __('Two-factor authentication must be set up before continuing.'));
        }

        return redirect()->route('two-factor.setup')->with(
            'status',
            __('Your role requires two-factor authentication. Set it up to continue.'),
        );
    }

    /**
     * Whether this installation makes two-factor mandatory for this account.
     *
     * Public and static so the enrolment screen can ask the same question the gate asks,
     * and therefore knows not to offer a "turn it off" button that the next request would
     * simply undo.
     *
     * The roles themselves come from {@see TwoFactorRequirement}, which reads the workspace's
     * own stored list and unions it with `config/planvio.php` — so a workspace that has
     * chosen its roles on the security settings screen is honoured, an installation-wide
     * config entry is honoured everywhere, and neither can cancel the other.
     */
    public static function isRequiredFor(User $user, ?Workspace $workspace = null): bool
    {
        return app(TwoFactorRequirement::class)->appliesTo($user, $workspace);
    }
}
