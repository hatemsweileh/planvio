<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * The second factor, submitted between a correct password and an authenticated session.
 *
 * Either a six-digit code from the authenticator app or one of the recovery codes issued at
 * enrolment. Exactly one of the two is required, so a submission with both empty fails in
 * the validator rather than reaching the verifier and burning a rate-limit attempt.
 */
final class TwoFactorChallengeRequest extends FormRequest
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
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:16'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:64'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');
        $recoveryCode = $this->input('recovery_code');

        $this->merge([
            'code' => is_string($code) ? trim($code) : $code,
            'recovery_code' => is_string($recoveryCode) ? trim($recoveryCode) : $recoveryCode,
        ]);
    }

    public function code(): ?string
    {
        $code = (string) $this->string('code');

        return $code === '' ? null : $code;
    }

    public function recoveryCode(): ?string
    {
        $code = Str::upper((string) $this->string('recovery_code'));

        return $code === '' ? null : $code;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => __('authentication code'),
            'recovery_code' => __('recovery code'),
        ];
    }
}
