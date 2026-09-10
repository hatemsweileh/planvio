<?php

declare(strict_types=1);

namespace App\Livewire\Installer;

use App\Livewire\Installer\Concerns\WizardScreen;
use App\Services\Install\MailCredentials;
use App\Services\Install\MailTester;
use App\Services\Install\WizardStep;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * SMTP, and the option to deal with it later (spec §71).
 *
 * Skipping is a real answer, not a way of postponing a required field: `MAIL_MAILER=log` is
 * written instead, so invitations and password resets land in `storage/logs` rather than
 * vanishing, and the administrator can configure a real server from the admin panel once they
 * have credentials for one.
 *
 * The test message is sent to the administrator's own address, which is the only address the
 * installer knows is real and the one they can actually check.
 */
final class Email extends Component
{
    use WizardScreen;

    public bool $configured = true;

    public string $host = '';

    public int $port = 587;

    public string $encryption = MailCredentials::ENCRYPTION_TLS;

    public string $username = '';

    public string $password = '';

    public string $fromAddress = '';

    public string $fromName = '';

    /**
     * @var array{status: string, message: string, detail: string|null, reference: string|null}|array{}
     */
    public array $result = [];

    protected function step(): WizardStep
    {
        return WizardStep::Email;
    }

    public function mount(): void
    {
        if ($this->guardOrder()) {
            return;
        }

        $application = $this->state()->get(WizardStep::Application);
        $administrator = $this->state()->get(WizardStep::Administrator);
        $saved = $this->state()->get(WizardStep::Email);

        $this->fromName = $this->stringOr($saved, 'from_name', $this->stringOr($application, 'name', 'Planvio'));
        $this->fromAddress = $this->stringOr($saved, 'from_address', $this->stringOr($administrator, 'email', ''));

        if ($saved === []) {
            return;
        }

        $this->configured = (bool) ($saved['configured'] ?? true);
        $this->host = $this->stringOr($saved, 'host', '');
        $this->port = is_numeric($saved['port'] ?? null) ? (int) $saved['port'] : 587;
        $this->encryption = $this->stringOr($saved, 'encryption', MailCredentials::ENCRYPTION_TLS);
        $this->username = $this->stringOr($saved, 'username', '');
        $this->password = $this->stringOr($saved, 'password', '');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'host' => ['required_if:configured,true', 'nullable', 'string', 'max:191'],
            'port' => ['required_if:configured,true', 'nullable', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::in(array_keys(MailCredentials::encryptionOptions()))],
            'username' => ['nullable', 'string', 'max:191'],
            'password' => ['nullable', 'string', 'max:191'],
            'fromAddress' => ['required_if:configured,true', 'nullable', 'string', 'email:rfc', 'max:191'],
            'fromName' => ['required_if:configured,true', 'nullable', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'host' => __('SMTP host'),
            'port' => __('port'),
            'fromAddress' => __('from address'),
            'fromName' => __('from name'),
        ];
    }

    public function sendTest(MailTester $tester): void
    {
        $this->validate();

        $recipient = $this->state()->get(WizardStep::Administrator)['email'] ?? $this->fromAddress;

        $this->result = $tester->test(
            $this->credentials(),
            is_string($recipient) ? $recipient : $this->fromAddress,
        )->toArray();
    }

    public function save(): void
    {
        $this->validate();

        $this->advance([
            'configured' => $this->configured,
            'host' => trim($this->host),
            'port' => $this->port,
            'encryption' => $this->encryption,
            'username' => trim($this->username),
            'password' => $this->password,
            'from_address' => mb_strtolower(trim($this->fromAddress)),
            'from_name' => trim($this->fromName),
        ]);
    }

    /**
     * Skipping still records an answer, so the wizard knows this screen was dealt with rather
     * than abandoned.
     */
    public function skip(): void
    {
        $this->advance([
            'configured' => false,
            'from_address' => mb_strtolower(trim($this->fromAddress)),
            'from_name' => trim($this->fromName),
        ]);
    }

    public function back(): void
    {
        $this->goBack();
    }

    public function updated(string $property): void
    {
        $this->result = [];
    }

    /**
     * @return array<string, string>
     */
    public function encryptionOptions(): array
    {
        return MailCredentials::encryptionOptions();
    }

    public function render(): View
    {
        return $this->screen(
            'installer.steps.email',
            __('Set up email'),
            __('Planvio sends invitations, password resets and notifications over plain SMTP — the details are in your hosting control panel under “Email Accounts”. You can skip this and configure it later.'),
        );
    }

    private function credentials(): MailCredentials
    {
        return MailCredentials::smtp(
            host: $this->host,
            port: $this->port,
            encryption: $this->encryption,
            username: $this->username,
            password: $this->password,
            fromAddress: $this->fromAddress,
            fromName: $this->fromName,
        );
    }

    /**
     * @param array<string, mixed> $saved
     */
    private function stringOr(array $saved, string $key, string $fallback): string
    {
        $value = $saved[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
