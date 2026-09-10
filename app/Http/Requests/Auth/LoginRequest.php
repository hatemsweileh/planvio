<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The sign-in form, and the rate limit that guards it.
 *
 * The limiter is keyed on the email *and* the client address together. Keying on the address
 * alone punishes everyone behind one office NAT for a single mistyped password; keying on the
 * email alone hands anybody a way to lock a colleague out of their own account by failing to
 * sign in as them. Together, an attacker has to move address to keep guessing one account,
 * and a shared address cannot be poisoned by one user's typing.
 */
final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:filter', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    /**
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'email' => (string) $this->string('email'),
            'password' => (string) $this->string('password'),
        ];
    }

    public function remember(): bool
    {
        return $this->boolean('remember');
    }

    /**
     * @throws ValidationException when the account is locked out
     */
    public function ensureIsNotRateLimited(): void
    {
        if (RateLimiter::tooManyAttempts($this->lockoutKey(), 1)) {
            $this->throwThrottled($this->lockoutKey());
        }

        if (! RateLimiter::tooManyAttempts($this->throttleKey(), $this->maxAttempts())) {
            return;
        }

        // The window the attempts accumulated in is short; the lockout that follows is not.
        // Moving the block onto its own key is what lets those two durations differ.
        RateLimiter::hit($this->lockoutKey(), $this->lockoutMinutes() * 60);
        RateLimiter::clear($this->throttleKey());

        event(new Lockout($this));

        $this->throwThrottled($this->lockoutKey());
    }

    public function hitRateLimiter(): void
    {
        RateLimiter::hit($this->throttleKey(), $this->decayMinutes() * 60);
    }

    public function clearRateLimiter(): void
    {
        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->lockoutKey());
    }

    public function throttleKey(): string
    {
        return 'login|'.Str::transliterate(Str::lower((string) $this->string('email')).'|'.$this->ip());
    }

    private function lockoutKey(): string
    {
        return 'login-lockout|'.Str::transliterate(Str::lower((string) $this->string('email')).'|'.$this->ip());
    }

    private function maxAttempts(): int
    {
        return $this->positiveConfig('planvio.security.login.max_attempts', 5);
    }

    private function decayMinutes(): int
    {
        return $this->positiveConfig('planvio.security.login.decay_minutes', 5);
    }

    private function lockoutMinutes(): int
    {
        return $this->positiveConfig('planvio.security.login.lockout_minutes', 15);
    }

    private function positiveConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return max(1, is_numeric($value) ? (int) $value : $default);
    }

    /**
     * @throws ValidationException
     */
    private function throwThrottled(string $key): never
    {
        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }
}
