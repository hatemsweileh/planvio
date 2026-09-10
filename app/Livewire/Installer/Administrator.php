<?php

declare(strict_types=1);

namespace App\Livewire\Installer;

use App\Livewire\Installer\Concerns\WizardScreen;
use App\Rules\StrongPassword;
use App\Services\Install\WizardStep;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The first account: a platform administrator who will own the first workspace.
 *
 * The password policy is {@see StrongPassword}, the same object every other password screen
 * in Planvio uses, read from `config('planvio.security.password')`. An installer with its own
 * looser idea of a strong password would set the floor for the single most privileged account
 * on the system, which is exactly backwards.
 *
 * The password is confirmed rather than revealed. There is no "show password" toggle here on
 * purpose: this screen is frequently completed with somebody watching over a shoulder or a
 * screen being shared, and the confirmation field already catches the typo it would prevent.
 */
final class Administrator extends Component
{
    use WizardScreen;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    protected function step(): WizardStep
    {
        return WizardStep::Administrator;
    }

    public function mount(): void
    {
        if ($this->guardOrder()) {
            return;
        }

        $saved = $this->state()->get(WizardStep::Administrator);

        $this->name = is_string($saved['name'] ?? null) ? $saved['name'] : '';
        $this->email = is_string($saved['email'] ?? null) ? $saved['email'] : '';

        // The password is deliberately not restored into the field. It is in the session so
        // the engine can create the account; putting it back in the markup would publish it
        // into the page source on every revisit.
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'password' => ['required', 'string', 'same:passwordConfirmation', new StrongPassword],
            'passwordConfirmation' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => __('your name'),
            'email' => __('email address'),
            'password' => __('password'),
            'passwordConfirmation' => __('password confirmation'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'password.same' => __('The two passwords do not match.'),
        ];
    }

    public function save(): void
    {
        $this->validate();

        $this->advance([
            'name' => trim($this->name),
            'email' => mb_strtolower(trim($this->email)),
            'password' => $this->password,
        ]);
    }

    public function back(): void
    {
        $this->goBack();
    }

    public function passwordPolicy(): string
    {
        return StrongPassword::description();
    }

    public function render(): View
    {
        return $this->screen(
            'installer.steps.administrator',
            __('Create your account'),
            __('This account can administer the whole installation and owns the first workspace. You will sign in with it as soon as the installation finishes.'),
        );
    }
}
