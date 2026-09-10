<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Turning two-factor off, and re-issuing recovery codes.
 *
 * Both weaken the account, so both cost the current password. A borrowed session — an
 * unlocked laptop, a stolen cookie — should not be able to strip the second factor off an
 * account or mint itself a fresh set of codes.
 */
final class DisableTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['current_password' => __('current password')];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => __('auth.password'),
        ];
    }
}
