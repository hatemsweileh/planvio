<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Models\User;
use App\Services\NotificationDispatcher;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * What reaches you, and where.
 *
 * The stored shape is the one {@see NotificationDispatcher::channelsFor()}
 * reads, narrowest first: a category's own per-channel setting beats the global one, which
 * beats the default of "both". `mute_all` short-circuits everything.
 *
 *     {
 *       "mute_all": false,
 *       "channels":   { "database": true, "mail": true },
 *       "categories": { "task.assigned": { "database": true, "mail": false } }
 *     }
 *
 * Only settings that differ from the default are written. A preferences column full of
 * `true` would freeze today's defaults into every account that ever opened this page, so a
 * category left alone stays absent and keeps following whatever the default becomes.
 */
#[Layout('layouts.app')]
final class Notifications extends Component
{
    use Concerns\NeedsAWorkspace;

    public bool $muteAll = false;

    /** @var array<string, bool> */
    public array $channels = ['database' => true, 'mail' => true];

    /**
     * category => ['database' => bool, 'mail' => bool]
     *
     * @var array<string, array<string, bool>>
     */
    public array $categories = [];

    public function mount(): void
    {
        $this->ensureWorkspace();

        $user = $this->actor();
        $stored = is_array($user->notification_preferences) ? $user->notification_preferences : [];

        $this->muteAll = ($stored['mute_all'] ?? false) === true;

        $globals = is_array($stored['channels'] ?? null) ? $stored['channels'] : [];

        $this->channels = [
            'database' => ($globals['database'] ?? true) !== false,
            'mail' => ($globals['mail'] ?? true) !== false,
        ];

        $perCategory = is_array($stored['categories'] ?? null) ? $stored['categories'] : [];

        foreach (array_keys($this->catalogue()) as $group) {
            foreach ($this->catalogue()[$group]['items'] as $category => $label) {
                $setting = $perCategory[$category] ?? null;

                if ($setting === false) {
                    $this->categories[$category] = ['database' => false, 'mail' => false];

                    continue;
                }

                $setting = is_array($setting) ? $setting : [];

                $this->categories[$category] = [
                    'database' => ($setting['database'] ?? $this->channels['database']) !== false,
                    'mail' => ($setting['mail'] ?? $this->channels['mail']) !== false,
                ];
            }
        }
    }

    /**
     * Every category Planvio files a notification under, grouped the way somebody thinks
     * about them rather than the way the classes are namespaced.
     *
     * @return array<string, array{title: string, description: string, items: array<string, string>}>
     */
    public function catalogue(): array
    {
        return [
            'work' => [
                'title' => __('Your work'),
                'description' => __('Things that land on you personally.'),
                'items' => [
                    'task.assigned' => __('A task is assigned to you'),
                    'task.status_changed' => __('A task you follow changes status'),
                    'task.completed' => __('A task you follow is completed'),
                    'task.due_soon' => __('A task of yours is due soon'),
                    'task.overdue' => __('A task of yours is overdue'),
                ],
            ],
            'conversation' => [
                'title' => __('Conversation'),
                'description' => __('Comments and mentions.'),
                'items' => [
                    'comment.mentioned' => __('Somebody mentions you'),
                    'comment.posted' => __('A comment on something you follow'),
                ],
            ],
            'planning' => [
                'title' => __('Planning'),
                'description' => __('Milestones and the shape of the plan.'),
                'items' => [
                    'milestone.due_soon' => __('A milestone is due soon'),
                    'milestone.completed' => __('A milestone is completed'),
                ],
            ],
            'people' => [
                'title' => __('People'),
                'description' => __('Invitations to workspaces and projects.'),
                'items' => [
                    'workspace.invitation' => __('You are invited to a workspace'),
                    'project.invitation' => __('You are added to a project'),
                ],
            ],
            'ai' => [
                'title' => __('AI'),
                'description' => __('What the agent did, and what it is waiting on.'),
                'items' => [
                    'ai.approval_required' => __('An AI action is waiting for your approval'),
                    'ai.action_executed' => __('AI changed something of yours'),
                    'ai.run_failed' => __('An AI run failed'),
                ],
            ],
        ];
    }

    public function toggleAll(string $channel, bool $on): void
    {
        if (! array_key_exists($channel, $this->channels)) {
            return;
        }

        $this->channels[$channel] = $on;

        foreach ($this->categories as $category => $setting) {
            $this->categories[$category][$channel] = $on;
        }
    }

    public function save(): void
    {
        $user = $this->actor();

        $preferences = ['mute_all' => $this->muteAll];

        $globals = [];

        foreach (['database', 'mail'] as $channel) {
            if (($this->channels[$channel] ?? true) === false) {
                $globals[$channel] = false;
            }
        }

        if ($globals !== []) {
            $preferences['channels'] = $globals;
        }

        $categories = [];

        foreach ($this->categories as $category => $setting) {
            $database = ($setting['database'] ?? true) !== false;
            $mail = ($setting['mail'] ?? true) !== false;

            // Off on both channels is the compact "false" the dispatcher short-circuits on.
            if (! $database && ! $mail) {
                $categories[$category] = false;

                continue;
            }

            $overrides = [];

            if ($database !== (($this->channels['database'] ?? true) !== false)) {
                $overrides['database'] = $database;
            }

            if ($mail !== (($this->channels['mail'] ?? true) !== false)) {
                $overrides['mail'] = $mail;
            }

            if ($overrides !== []) {
                $categories[$category] = $overrides;
            }
        }

        if ($categories !== []) {
            $preferences['categories'] = $categories;
        }

        $user->notification_preferences = $preferences;
        $user->save();

        $this->dispatch('planvio-notify', type: 'success', message: __('Notification preferences saved.'));
    }

    public function render(): View
    {
        return view('livewire.app.profile.notifications')->title(__('Notification preferences'));
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
