<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Models\AuditLog;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\TwoFactorService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Everything that decides whether somebody else can be you: the password, the second
 * factor, the browsers still signed in, and the tokens acting on your behalf.
 *
 * Two things are shown exactly once and never again — recovery codes and an API token. Both
 * are credentials, and a screen that could reveal them later would turn any borrowed session
 * into a permanent bypass. Enrolment itself stays with the controller that already owns it
 * (`two-factor.*`), because that flow is also what the sign-in challenge and the enrolment
 * middleware depend on; this screen is its front door, not a second implementation.
 */
#[Layout('layouts.app')]
final class Security extends Component
{
    use Concerns\NeedsAWorkspace;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public bool $showTokenForm = false;

    public string $tokenName = '';

    /** Shown once, on the render that mints it. */
    #[Locked]
    public ?string $plainTextToken = null;

    public function mount(): void
    {
        $this->ensureWorkspace();
    }

    /* ------------------------------------------------------------------ *
     * Reads
     * ------------------------------------------------------------------ */

    public function user(): User
    {
        return $this->actor();
    }

    public function twoFactorRequired(): bool
    {
        return EnsureTwoFactorConfirmed::isRequiredFor($this->actor());
    }

    public function remainingRecoveryCodes(): int
    {
        return count(app(TwoFactorService::class)->recoveryCodes($this->actor()));
    }

    public function passwordPolicy(): string
    {
        return StrongPassword::description();
    }

    /**
     * The browsers currently holding a session for this account.
     *
     * Only meaningful with the database session driver; on any other driver the sessions
     * live somewhere this query cannot see, and claiming "one session" would be a lie.
     *
     * @return Collection<int, object{id: string, ip: string|null, agent: string|null, last_active: Carbon, current: bool}>
     */
    #[Computed]
    public function sessions(): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        $currentId = session()->getId();

        return collect(DB::table(config('session.table', 'sessions'))
            ->where('user_id', $this->actor()->getKey())
            ->orderByDesc('last_activity')
            ->limit(20)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']))
            ->map(fn (object $row): object => (object) [
                'id' => (string) $row->id,
                'ip' => $row->ip_address === null ? null : (string) $row->ip_address,
                'agent' => self::describeAgent($row->user_agent === null ? null : (string) $row->user_agent),
                'last_active' => Carbon::createFromTimestamp((int) $row->last_activity),
                'current' => (string) $row->id === $currentId,
            ]);
    }

    public function sessionsVisible(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        /** @var Collection<int, PersonalAccessToken> $tokens */
        $tokens = $this->actor()->tokens()
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at']);

        return $tokens;
    }

    /* ------------------------------------------------------------------ *
     * Password
     * ------------------------------------------------------------------ */

    public function updatePassword(): void
    {
        $user = $this->actor();

        $this->validate([
            'currentPassword' => ['required', 'string'],
            // The confirmation field is named for the property that holds it: Livewire
            // properties are camelCase, and the rule's default would look for
            // `newPassword_confirmation`, which nothing on this form is called.
            'newPassword' => ['required', 'string', 'confirmed:newPasswordConfirmation', new StrongPassword],
        ], attributes: [
            'currentPassword' => __('current password'),
            'newPassword' => __('new password'),
        ]);

        if (! Hash::check($this->currentPassword, (string) $user->password)) {
            $this->addError('currentPassword', __('That is not your current password.'));

            $this->audit('account.password_change_failed', __('Password change rejected.'));

            return;
        }

        $user->password = $this->newPassword;
        $user->setRememberToken(Str::random(60));
        $user->save();

        // The session that made the change keeps working; every other one is left holding a
        // password that no longer exists.
        $this->forgetOtherSessions();

        $this->reset(['currentPassword', 'newPassword', 'newPasswordConfirmation']);

        $this->audit('account.password_changed', __('Password changed.'));

        unset($this->sessions);

        $this->dispatch('planvio-notify', type: 'success', message: __('Password changed. Other browsers have been signed out.'));
    }

    /* ------------------------------------------------------------------ *
     * Sessions
     * ------------------------------------------------------------------ */

    public function signOutOthers(): void
    {
        $this->forgetOtherSessions();

        $this->audit('account.sessions_revoked', __('Other sessions signed out.'));

        unset($this->sessions);

        $this->dispatch('planvio-notify', type: 'success', message: __('Every other browser has been signed out.'));
    }

    public function revokeSession(string $sessionId): void
    {
        if (! $this->sessionsVisible() || $sessionId === session()->getId()) {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $this->actor()->getKey())
            ->where('id', $sessionId)
            ->delete();

        $this->audit('account.sessions_revoked', __('A session was signed out.'));

        unset($this->sessions);

        $this->dispatch('planvio-notify', type: 'success', message: __('That browser has been signed out.'));
    }

    /* ------------------------------------------------------------------ *
     * API tokens
     * ------------------------------------------------------------------ */

    public function startToken(): void
    {
        $this->tokenName = '';
        $this->plainTextToken = null;
        $this->showTokenForm = true;

        $this->resetValidation();
    }

    public function createToken(): void
    {
        $data = $this->validate([
            'tokenName' => ['required', 'string', 'min:2', 'max:80'],
        ], attributes: ['tokenName' => __('token name')]);

        $user = $this->actor();

        // No abilities are narrowed here: an API token acts as the person who made it, and
        // every endpoint it reaches re-runs the same policies the UI does. A token cannot
        // out-rank its owner.
        $token = $user->createToken($data['tokenName']);

        $this->plainTextToken = $token->plainTextToken;
        $this->showTokenForm = false;
        $this->tokenName = '';

        $this->audit('account.api_token_created', __('API token created.'), [
            'token_id' => (int) $token->accessToken->getKey(),
            'name' => $data['tokenName'],
        ]);

        unset($this->tokens);
    }

    public function revokeToken(int $tokenId): void
    {
        $user = $this->actor();

        $token = $user->tokens()->whereKey($tokenId)->first();

        if ($token === null) {
            return;
        }

        $name = (string) $token->name;

        $token->delete();

        $this->audit('account.api_token_revoked', __('API token revoked.'), [
            'token_id' => $tokenId,
            'name' => $name,
        ]);

        unset($this->tokens);

        $this->dispatch('planvio-notify', type: 'success', message: __('“:name” can no longer be used.', [
            'name' => $name,
        ]));
    }

    public function dismissToken(): void
    {
        $this->plainTextToken = null;
    }

    public function render(): View
    {
        return view('livewire.app.profile.security')->title(__('Security'));
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    private function forgetOtherSessions(): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $this->actor()->getKey())
            ->where('id', '!=', session()->getId())
            ->delete();
    }

    /**
     * A user agent string, reduced to the two facts a person recognises: the browser and
     * the platform. The raw string is stored; it is not what somebody reads.
     */
    private static function describeAgent(?string $agent): ?string
    {
        if ($agent === null || trim($agent) === '') {
            return null;
        }

        $browsers = [
            'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox',
            'Chrome/' => 'Chrome', 'Safari/' => 'Safari',
        ];

        $platforms = [
            'Windows' => 'Windows', 'Macintosh' => 'macOS', 'Mac OS X' => 'macOS',
            'Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad',
            'Linux' => 'Linux', 'CrOS' => 'ChromeOS',
        ];

        $browser = null;

        foreach ($browsers as $needle => $label) {
            if (str_contains($agent, $needle)) {
                $browser = $label;

                break;
            }
        }

        $platform = null;

        foreach ($platforms as $needle => $label) {
            if (str_contains($agent, $needle)) {
                $platform = $label;

                break;
            }
        }

        if ($browser === null && $platform === null) {
            return mb_substr($agent, 0, 60);
        }

        return trim(($browser ?? __('Unknown browser')).' · '.($platform ?? __('Unknown device')), ' ·');
    }

    /**
     * @param array<string, scalar|array<array-key, mixed>|null> $properties
     */
    private function audit(string $event, string $description, array $properties = []): void
    {
        AuditLog::query()->create([
            'user_id' => $this->actor()->getKey(),
            'event' => $event,
            'description' => $description,
            'ip' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
            'properties' => $properties === [] ? null : $properties,
        ]);
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
