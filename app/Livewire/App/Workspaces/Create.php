<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces;

use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\WorkspaceAttributes;
use App\Models\Workspace;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Onboarding, and the "New workspace" item in the switcher — the same screen either way.
 *
 * It renders on the guest shell rather than the product one, because an account with no
 * membership has no sidebar to draw: every item in it is addressed per workspace. That also
 * makes this the one product screen a brand-new account can reach.
 */
final class Create extends Component
{
    public string $name = '';

    public string $timezone = 'UTC';

    public string $currency = 'USD';

    public function mount(): void
    {
        $this->authorize('create', Workspace::class);

        $user = auth()->user();

        $this->timezone = $this->isKnownTimezone($user?->timezone)
            ? (string) $user->timezone
            : (string) config('planvio.defaults.workspace.timezone', 'UTC');

        $this->currency = (string) config('planvio.defaults.workspace.currency', 'USD');
    }

    public function save(CreateWorkspace $createWorkspace): void
    {
        // Authorisation is the caller's job: Actions validate domain invariants, never
        // permissions (ARCHITECTURE.md §2).
        $this->authorize('create', Workspace::class);

        $data = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'timezone' => ['required', 'string', Rule::in($this->timezones())],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
        ]);

        $workspace = $createWorkspace(auth()->user(), new WorkspaceAttributes(
            name: $data['name'],
            timezone: $data['timezone'],
            currency: mb_strtoupper($data['currency']),
        ));

        session()->flash('status', __('“:workspace” is ready.', ['workspace' => $workspace->name]));

        $this->redirect(route('app.home', $workspace));
    }

    /**
     * @return list<string>
     */
    public function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    public function render(): View
    {
        return view('livewire.app.workspaces.create')
            ->extends('layouts.guest')
            ->section('content')
            ->layoutData([
                'title' => __('New workspace'),
                'heading' => auth()->user()?->workspaces()->exists()
                    ? __('New workspace')
                    : __('Create your first workspace'),
                'subheading' => __('A workspace is one organisation, team or client. Projects, people and settings live inside it.'),
            ]);
    }

    private function isKnownTimezone(?string $timezone): bool
    {
        return $timezone !== null && in_array($timezone, $this->timezones(), true);
    }
}
