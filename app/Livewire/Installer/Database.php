<?php

declare(strict_types=1);

namespace App\Livewire\Installer;

use App\Livewire\Installer\Concerns\WizardScreen;
use App\Services\Install\DatabaseCredentials;
use App\Services\Install\DatabaseTester;
use App\Services\Install\WizardStep;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Where Planvio is told which database to use.
 *
 * The connection is proved before the wizard moves on, not merely collected: everything after
 * this screen assumes a reachable database, and finding out otherwise three screens later —
 * with a password already typed — is the difference between a two-minute install and a
 * support ticket. The details are only stored once a test has actually succeeded.
 */
final class Database extends Component
{
    use WizardScreen;

    public string $host = '127.0.0.1';

    public int $port = 3306;

    public string $database = '';

    public string $username = '';

    public string $password = '';

    /**
     * The last test result, flattened for the Livewire payload.
     *
     * @var array{status: string, message: string, detail: string|null, reference: string|null}|array{}
     */
    public array $result = [];

    protected function step(): WizardStep
    {
        return WizardStep::Database;
    }

    public function mount(): void
    {
        if ($this->guardOrder()) {
            return;
        }

        $saved = $this->state()->get(WizardStep::Database);

        $this->host = is_string($saved['host'] ?? null) ? $saved['host'] : '127.0.0.1';
        $this->port = is_numeric($saved['port'] ?? null) ? (int) $saved['port'] : 3306;
        $this->database = is_string($saved['database'] ?? null) ? $saved['database'] : '';
        $this->username = is_string($saved['username'] ?? null) ? $saved['username'] : '';
        $this->password = is_string($saved['password'] ?? null) ? $saved['password'] : '';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:191'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:191'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'host' => __('database host'),
            'port' => __('port'),
            'database' => __('database name'),
            'username' => __('database user'),
            'password' => __('database password'),
        ];
    }

    public function test(DatabaseTester $tester): void
    {
        $this->validate();

        $this->result = $tester->test($this->credentials())->toArray();
    }

    public function save(DatabaseTester $tester): void
    {
        $this->validate();

        $result = $tester->test($this->credentials());
        $this->result = $result->toArray();

        if (! $result->ok()) {
            return;
        }

        $this->advance([
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
        ]);
    }

    public function back(): void
    {
        $this->goBack();
    }

    /**
     * A stale result is worse than none: it would say "connected" beside a password that has
     * since been retyped.
     */
    public function updated(string $property): void
    {
        $this->result = [];
    }

    public function render(): View
    {
        return $this->screen(
            'installer.steps.database',
            __('Connect your database'),
            __('Planvio stores everything in one MySQL or MariaDB database. Create an empty one first, then enter its details here.'),
        );
    }

    private function credentials(): DatabaseCredentials
    {
        return DatabaseCredentials::mysql(
            host: $this->host,
            port: $this->port,
            database: $this->database,
            username: $this->username,
            password: $this->password,
        );
    }
}
