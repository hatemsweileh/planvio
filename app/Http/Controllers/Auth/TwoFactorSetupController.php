<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\WritesAuthAuditLog;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Http\Requests\Auth\ConfirmTwoFactorRequest;
use App\Http\Requests\Auth\DisableTwoFactorRequest;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Enrolling in, confirming and removing two-factor authentication.
 *
 * Enrolment is two steps on purpose. Generating a secret only stores it; it does not take
 * effect until a code produced from it verifies, which proves the authenticator app really
 * holds the same secret. Making the secret live at generation time would lock out anybody
 * whose scan failed or whose phone clock is wrong.
 *
 * Recovery codes are shown exactly once, right after they are minted. They are stored
 * encrypted and there is no screen that reveals them again — a screen like that would turn
 * any borrowed session into a permanent bypass of the second factor.
 */
final class TwoFactorSetupController extends Controller
{
    use WritesAuthAuditLog;

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function show(Request $request): View
    {
        $user = $this->user($request);
        $secret = $user->two_factor_secret;
        $pending = is_string($secret) && $secret !== '' && ! $user->hasTwoFactorEnabled();

        return view('auth.two-factor-setup', [
            'enabled' => $user->hasTwoFactorEnabled(),
            'pending' => $pending,
            'qrCode' => $pending ? $this->twoFactor->qrCodeSvg($user, $secret) : null,
            'secret' => $pending ? $secret : null,
            'recoveryCodes' => $this->flashedRecoveryCodes($request),
            'remainingRecoveryCodes' => count($this->twoFactor->recoveryCodes($user)),
            // Asked of the same gate the middleware uses, so the screen never offers a
            // "turn it off" button that the next request would immediately undo.
            'required' => EnsureTwoFactorConfirmed::isRequiredFor($user),
        ]);
    }

    /**
     * Mint a secret and a set of recovery codes, without putting either in force.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.setup');
        }

        $this->twoFactor->enable(
            $user,
            $this->twoFactor->generateSecret(),
            $this->twoFactor->generateRecoveryCodes(),
        );

        $this->audit($request, 'auth.two_factor_enrolled', $user, __('Two-factor enrolment started.'));

        return redirect()->route('two-factor.setup');
    }

    /**
     * @throws ValidationException when the submitted code does not match the pending secret
     */
    public function confirm(ConfirmTwoFactorRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            return redirect()->route('two-factor.setup');
        }

        if (! $this->twoFactor->verify($secret, (string) $request->string('code'))) {
            $this->audit($request, 'auth.two_factor_confirm_failed', $user, __('Two-factor confirmation rejected.'));

            throw ValidationException::withMessages([
                'code' => __('That authentication code is not valid.'),
            ]);
        }

        $this->twoFactor->confirm($user);

        $this->audit($request, 'auth.two_factor_enabled', $user, __('Two-factor authentication enabled.'));

        return redirect()->route('two-factor.setup')
            ->with('status', __('Two-factor authentication is on.'))
            ->with('recoveryCodes', $this->twoFactor->recoveryCodes($user));
    }

    /**
     * Issue a fresh set of recovery codes, retiring the old ones.
     */
    public function recoveryCodes(DisableTwoFactorRequest $request): RedirectResponse
    {
        $user = $this->user($request);

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.setup');
        }

        $codes = $this->twoFactor->replaceRecoveryCodes($user);

        $this->audit(
            $request,
            'auth.two_factor_recovery_codes_regenerated',
            $user,
            __('Two-factor recovery codes regenerated.'),
            ['count' => count($codes)],
        );

        return redirect()->route('two-factor.setup')
            ->with('status', __('New recovery codes have been generated.'))
            ->with('recoveryCodes', $codes);
    }

    public function destroy(DisableTwoFactorRequest $request): RedirectResponse
    {
        $user = $this->user($request);

        $this->twoFactor->disable($user);

        $this->audit($request, 'auth.two_factor_disabled', $user, __('Two-factor authentication disabled.'));

        return redirect()->route('two-factor.setup')
            ->with('status', __('Two-factor authentication is off.'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @return list<string>|null
     */
    private function flashedRecoveryCodes(Request $request): ?array
    {
        $codes = $request->session()->get('recoveryCodes');

        if (! is_array($codes) || $codes === []) {
            return null;
        }

        return array_values(array_map(static fn (mixed $code): string => (string) $code, $codes));
    }
}
