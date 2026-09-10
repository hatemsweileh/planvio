<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\Branding;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Exceptions\Google2FAException;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

/**
 * Time-based one-time passwords and their recovery codes.
 *
 * The QR code is rendered locally, as inline SVG. The usual shortcut — handing the
 * `otpauth://` URI to a chart service and pointing an `<img>` at it — would post the shared
 * secret to a third party inside a URL, which is exactly what a self-hosted install exists
 * to avoid. Nothing about enrolment leaves the server.
 *
 * Secrets and recovery codes are written through the model's encrypted casts, so they are
 * ciphertext at rest and never appear in logs (CLAUDE.md rule 4).
 */
final class TwoFactorService
{
    /** Recovery codes are read aloud and typed by hand, so 0/O and 1/I/L are left out. */
    private const RECOVERY_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const RECOVERY_GROUP_LENGTH = 5;

    private const RECOVERY_GROUPS = 2;

    private const QR_SIZE = 220;

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly Branding $branding,
    ) {}

    /**
     * A fresh base32 shared secret. 32 characters is 160 bits, the size RFC 4226 recommends.
     */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /**
     * The `otpauth://` enrolment URI an authenticator app expects.
     */
    public function provisioningUri(User $user, #[SensitiveParameter] string $secret): string
    {
        $issuer = $this->branding->name();
        $label = $issuer.':'.($user->email ?? (string) $user->getKey());

        return 'otpauth://totp/'.rawurlencode($label).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The enrolment QR code as an inline SVG document.
     */
    public function qrCodeSvg(User $user, #[SensitiveParameter] string $secret): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(self::QR_SIZE, 0, null, null, Fill::default()),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString($this->provisioningUri($user, $secret));

        // Bacon emits a standalone document; its XML declaration is invalid inside HTML.
        $svg = preg_replace('/<\?xml.*?\?>\s*/s', '', $svg) ?? $svg;

        return trim($svg);
    }

    /**
     * Whether a submitted six-digit code matches the secret within the configured window.
     */
    public function verify(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($code === '' || $secret === '') {
            return false;
        }

        try {
            return $this->google2fa->verifyKey($secret, $code, $this->window()) !== false;
        } catch (Google2FAException) {
            // A malformed secret, or a code carrying illegal characters, is a failed
            // attempt rather than a server fault.
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        $configured = config('planvio.security.two_factor.recovery_code_count', 8);
        $count = max(1, min(20, is_numeric($configured) ? (int) $configured : 8));

        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = $this->recoveryCode();
        }

        return $codes;
    }

    /**
     * Spend a recovery code: single use, removed from the user the moment it matches.
     *
     * Comparison is constant-time and the surviving list is written in one save, so the
     * code cannot be replayed against the row it just cleared.
     */
    public function consumeRecoveryCode(User $user, #[SensitiveParameter] string $code): bool
    {
        $candidate = $this->normaliseRecoveryCode($code);

        if ($candidate === '') {
            return false;
        }

        $remaining = [];
        $matched = false;

        foreach ($this->recoveryCodes($user) as $existing) {
            if (! $matched && hash_equals($this->normaliseRecoveryCode($existing), $candidate)) {
                $matched = true;

                continue;
            }

            $remaining[] = $existing;
        }

        if (! $matched) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();

        return true;
    }

    /**
     * @return list<string>
     */
    public function recoveryCodes(User $user): array
    {
        $codes = $user->two_factor_recovery_codes;

        if (! is_array($codes)) {
            return [];
        }

        $strings = array_map(
            static fn (mixed $code): string => is_string($code) ? $code : '',
            $codes,
        );

        return array_values(array_filter($strings, static fn (string $code): bool => $code !== ''));
    }

    /**
     * Store a secret and its recovery codes without putting them in force.
     *
     * Two-factor only takes effect at {@see self::confirm()}: somebody who enrols and then
     * fails to scan the QR code must not be locked out of their own account.
     *
     * @param list<string> $recoveryCodes
     */
    public function enable(User $user, #[SensitiveParameter] string $secret, array $recoveryCodes): void
    {
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function confirm(User $user): void
    {
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * Replace the recovery codes, leaving the secret and its confirmation untouched.
     *
     * @return list<string>
     */
    public function replaceRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    private function window(): int
    {
        $window = config('planvio.security.two_factor.window', 1);

        return max(0, min(8, is_numeric($window) ? (int) $window : 1));
    }

    private function recoveryCode(): string
    {
        $groups = [];

        for ($group = 0; $group < self::RECOVERY_GROUPS; $group++) {
            $groups[] = $this->randomGroup();
        }

        return implode('-', $groups);
    }

    private function randomGroup(): string
    {
        $max = strlen(self::RECOVERY_ALPHABET) - 1;
        $group = '';

        for ($i = 0; $i < self::RECOVERY_GROUP_LENGTH; $i++) {
            $group .= self::RECOVERY_ALPHABET[random_int(0, $max)];
        }

        return $group;
    }

    private function normaliseRecoveryCode(string $code): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }
}
