<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Controllers\Auth\RegisterController;
use App\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Self-service registration.
 *
 * Whether this form is reachable at all is an administrator's decision, enforced in
 * {@see RegisterController}; the rules here assume it is.
 */
final class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email:filter',
                'max:255',
                // Queries the table directly rather than the model, so soft-deleted
                // accounts still hold their address — which is what the unique index does
                // too. Anything narrower would fail in the database instead of here.
                Rule::unique('users', 'email'),
            ],
            'password' => ['required', 'string', 'confirmed', new StrongPassword],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $name = $this->input('name');

        $this->merge([
            'email' => is_string($email) ? Str::lower(trim($email)) : $email,
            'name' => is_string($name) ? trim($name) : $name,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('name'),
            'email' => __('email address'),
            'password' => __('password'),
        ];
    }
}
