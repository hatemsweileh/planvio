<?php

declare(strict_types=1);

namespace App\Livewire\App\Settings;

use App\Models\AiSetting;
use App\Models\CustomField;
use App\Models\ProjectStatus;
use App\Models\ProjectTemplate;
use App\Models\Tag;
use App\Models\Webhook;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Workspace settings: one route, one rail, and a live child component per section.
 *
 * Sections are nested Livewire components rather than branches of a single class. Each one
 * owns its own form state, its own validation and its own authorization, so saving a
 * webhook cannot disturb an unsaved accent colour, and a section the acting user may not
 * touch is never mounted at all.
 *
 * The section lives in the query string, so a link to `?section=webhooks` is a link to the
 * webhooks page — which is what people paste to each other — without adding a route per
 * section to the table every other screen resolves by name.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /**
     * section key => [component, icon, ability, subject].
     *
     * The ability is asked of the Gate with the workspace as the subject, which is the same
     * question the section's own mount() asks before it renders anything.
     *
     * `context` says whether the workspace is passed alongside the subject: most of these
     * policies accept `Workspace|Project|null`, but CustomFieldPolicy narrows by project
     * only, and handing it a tenant would be a type error rather than a check. Its own
     * fallback is the bound workspace, which is this one.
     *
     * @var array<string, array{component: string, icon: string, ability: string, subject: class-string, context: bool}>
     */
    private const SECTIONS = [
        'general' => [
            'component' => 'app.settings.general',
            'icon' => 'icon.cog',
            'ability' => 'update',
            'subject' => Workspace::class,
            'context' => true,
        ],
        'statuses' => [
            'component' => 'app.settings.statuses',
            'icon' => 'icon.board',
            'ability' => 'viewAny',
            'subject' => ProjectStatus::class,
            'context' => true,
        ],
        'tags' => [
            'component' => 'app.settings.tags',
            'icon' => 'icon.tag',
            'ability' => 'viewAny',
            'subject' => Tag::class,
            'context' => true,
        ],
        'fields' => [
            'component' => 'app.settings.fields',
            'icon' => 'icon.list',
            'ability' => 'viewAny',
            'subject' => CustomField::class,
            'context' => false,
        ],
        'templates' => [
            'component' => 'app.settings.templates',
            'icon' => 'icon.folder',
            'ability' => 'viewAny',
            'subject' => ProjectTemplate::class,
            'context' => true,
        ],
        'webhooks' => [
            'component' => 'app.settings.webhooks',
            'icon' => 'icon.monitor',
            'ability' => 'viewAny',
            'subject' => Webhook::class,
            'context' => true,
        ],
        /*
         | Gated on `update` against the workspace rather than on a model policy, because
         | what it edits is not a record: it is who has to hold a second factor before they
         | can act here. That is the same authority that renames the workspace and deletes
         | its projects, and it is deliberately not delegable to a manager.
         */
        'security' => [
            'component' => 'app.settings.security',
            'icon' => 'icon.shield',
            'ability' => 'update',
            'subject' => Workspace::class,
            'context' => true,
        ],
        'ai' => [
            'component' => 'app.settings.ai',
            'icon' => 'icon.sparkles',
            'ability' => 'viewAny',
            'subject' => AiSetting::class,
            'context' => true,
        ],
    ];

    public Workspace $workspace;

    #[Url(except: 'general')]
    public string $section = 'general';

    public function mount(Workspace $workspace): void
    {
        $this->authorize('view', $workspace);
        $this->authorize('workspace.manage', $workspace);

        $this->workspace = $workspace;

        if (! array_key_exists($this->section, $this->available())) {
            $this->section = (string) array_key_first($this->available());
        }
    }

    /**
     * The sections this person may actually open, in order.
     *
     * @return array<string, array{label: string, description: string, icon: string, component: string}>
     */
    #[Computed]
    public function available(): array
    {
        $labels = $this->labels();
        $sections = [];

        foreach (self::SECTIONS as $key => $definition) {
            $arguments = $definition['context']
                ? [$definition['subject'], $this->workspace]
                : [$definition['subject']];

            if (! Gate::allows($definition['ability'], $arguments)) {
                continue;
            }

            $sections[$key] = [
                'label' => $labels[$key]['label'],
                'description' => $labels[$key]['description'],
                'icon' => $definition['icon'],
                'component' => $definition['component'],
            ];
        }

        return $sections;
    }

    public function setSection(string $section): void
    {
        if (array_key_exists($section, $this->available())) {
            $this->section = $section;
        }
    }

    public function render(): View
    {
        return view('livewire.app.settings.index')->title(__('Workspace settings'));
    }

    /**
     * Kept out of the constant because a constant cannot be translated.
     *
     * @return array<string, array{label: string, description: string}>
     */
    private function labels(): array
    {
        return [
            'general' => [
                'label' => __('General'),
                'description' => __('Name, logo, accent, timezone and formats'),
            ],
            'statuses' => [
                'label' => __('Project statuses'),
                'description' => __('The lifecycle every project moves through'),
            ],
            'tags' => [
                'label' => __('Tags'),
                'description' => __('Shared labels for tasks and projects'),
            ],
            'fields' => [
                'label' => __('Custom fields'),
                'description' => __('Extra attributes on tasks and projects'),
            ],
            'templates' => [
                'label' => __('Project templates'),
                'description' => __('Blueprints new projects can start from'),
            ],
            'webhooks' => [
                'label' => __('Webhooks'),
                'description' => __('Signed outbound events for other systems'),
            ],
            'security' => [
                'label' => __('Security'),
                'description' => __('Who must hold a second factor to work here'),
            ],
            'ai' => [
                'label' => __('AI'),
                'description' => __('What the agent may do, and on whose say-so'),
            ],
        ];
    }
}
