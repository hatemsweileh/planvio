<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Proving the authenticator app was actually set up before two-factor is put in force.
 *
 * Without this step somebody could store a secret they never managed to scan and lock
 * themselves out at the next sign-in.
 */
final class ConfirmTwoFactorRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:16'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        if (is_string($code)) {
            $this->merge(['code' => trim($code)]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => __('authentication code')];
    }
}
