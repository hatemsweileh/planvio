<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * "Email me a reset link".
 *
 * The rules stop at "this is a well-formed address" on purpose. A `exists:users` rule here
 * would turn the form into an account oracle: anybody could type an address and learn from
 * the validation error whether it belongs to a Planvio user. The controller answers
 * identically either way (docs/SECURITY.md).
 */
final class PasswordResetLinkRequest extends FormRequest
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
        return ['email' => __('email address')];
    }
}
