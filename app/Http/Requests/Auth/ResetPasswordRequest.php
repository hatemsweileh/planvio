<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Choosing a new password from a reset link.
 *
 * As with the request form, nothing here asserts that the address exists: the token is the
 * credential, and the broker's answer is flattened into one message by the controller so a
 * wrong address and an expired token are indistinguishable.
 */
final class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email:filter', 'max:255'],
            'password' => ['required', 'string', 'confirmed', new StrongPassword],
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
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => __('email address'),
            'password' => __('password'),
        ];
    }
}
