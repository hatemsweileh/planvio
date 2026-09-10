{{--
    Design-system gallery. Rendered to a static file for visual QA:

        ./php artisan tinker --execute="file_put_contents('public/_gallery.html', view('dev.gallery')->render());"

    Not routed, not shipped in the release (resources/views/dev is excluded from the build).
--}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Planvio design system</title>
    <link rel="stylesheet" href="{{ '/build/'.json_decode(file_get_contents(public_path('build/manifest.json')), true)['resources/css/app.css']['file'] }}">
    <script>
        (function () {
            var p = new URLSearchParams(location.search).get('theme');
            if (p === 'dark') { document.documentElement.classList.add('dark'); document.documentElement.style.colorScheme = 'dark'; }
        })();
    </script>
</head>
<body class="min-h-full">

<div class="mx-auto max-w-5xl space-y-10 p-8">

    <header class="flex items-center justify-between gap-4 border-b border-[var(--line-subtle)] pb-6">
        <div class="flex items-center gap-3">
            <img src="/img/brand/planvio-mark.svg" alt="" class="h-9 text-[var(--text-strong)]" style="color: var(--text-strong)">
            <div>
                <h1 class="text-xl font-semibold text-[var(--text-strong)]">Planvio design system</h1>
                <p class="text-sm text-[var(--text-muted)]">Brand 600 is exactly #3F66B0, the logo blue.</p>
            </div>
        </div>
        <div class="flex gap-2">
            <a href="?theme=light" class="text-xs underline text-[var(--accent)]">Light</a>
            <a href="?theme=dark" class="text-xs underline text-[var(--accent)]">Dark</a>
        </div>
    </header>

    <section>
        <h2 class="mb-3 text-sm font-semibold text-[var(--text-strong)]">Brand scale</h2>
        {{--
            Class names must be written out in full. Tailwind scans source text and never
            evaluates it, so `bg-brand-{{ $step }}` would compile to nothing at all — the
            single most common way a Tailwind build silently loses styles.
        --}}
        <div class="flex overflow-hidden rounded-lg border border-[var(--line-subtle)]">
            @foreach ([
                ['bg-brand-50', '50'], ['bg-brand-100', '100'], ['bg-brand-200', '200'],
                ['bg-brand-300', '300'], ['bg-brand-400', '400'], ['bg-brand-500', '500'],
                ['bg-brand-600', '600'], ['bg-brand-700', '700'], ['bg-brand-800', '800'],
                ['bg-brand-900', '900'], ['bg-brand-950', '950'],
            ] as [$class, $step])
                <div class="flex-1">
                    <div class="h-14 {{ $class }}"></div>
                    <div class="bg-[var(--surface-panel)] py-1 text-center text-[10px] text-[var(--text-muted)]">{{ $step }}</div>
                </div>
            @endforeach
        </div>
    </section>

    <section>
        <h2 class="mb-3 text-sm font-semibold text-[var(--text-strong)]">Buttons</h2>
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button variant="primary">Create project</x-ui.button>
            <x-ui.button variant="secondary">Cancel</x-ui.button>
            <x-ui.button variant="ghost">Filter</x-ui.button>
            <x-ui.button variant="soft">Approve</x-ui.button>
            <x-ui.button variant="danger">Delete</x-ui.button>
            <x-ui.button variant="danger-ghost">Remove</x-ui.button>
            <x-ui.button variant="link">Learn more</x-ui.button>
            <x-ui.button variant="primary" :loading="true">Saving</x-ui.button>
            <x-ui.button variant="secondary" icon="icon.plus">With icon</x-ui.button>
            <x-ui.button variant="secondary" icon-only aria-label="More"><x-icon.dots class="size-4" /></x-ui.button>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <x-ui.button size="xs" variant="secondary">Extra small</x-ui.button>
            <x-ui.button size="sm" variant="secondary">Small</x-ui.button>
            <x-ui.button size="md" variant="secondary">Medium</x-ui.button>
            <x-ui.button size="lg" variant="secondary">Large</x-ui.button>
        </div>
    </section>

    <section>
        <h2 class="mb-3 text-sm font-semibold text-[var(--text-strong)]">Badges</h2>
        <div class="flex flex-wrap items-center gap-2">
            @foreach (['gray' => 'Backlog', 'blue' => 'To Do', 'brand' => 'In Progress', 'purple' => 'Review', 'red' => 'Blocked', 'green' => 'Completed', 'amber' => 'At Risk', 'orange' => 'High', 'teal' => 'Finance', 'pink' => 'Design'] as $color => $label)
                <x-ui.badge :color="$color" dot>{{ $label }}</x-ui.badge>
            @endforeach
        </div>
    </section>

    <section>
        <h2 class="mb-3 text-sm font-semibold text-[var(--text-strong)]">Avatars</h2>
        <div class="flex items-end gap-4">
            <x-ui.avatar name="Sarah Klein" size="xs" />
            <x-ui.avatar name="Daniel Okoye" size="sm" />
            <x-ui.avatar name="Hatem Rahman" size="md" />
            <x-ui.avatar name="Mei Chen" size="lg" />
            <x-ui.avatar name="Priya Nair" size="xl" />
            <x-ui.avatar-stack :users="collect([
                (object) ['name' => 'Sarah Klein', 'avatar_path' => null],
                (object) ['name' => 'Daniel Okoye', 'avatar_path' => null],
                (object) ['name' => 'Mei Chen', 'avatar_path' => null],
                (object) ['name' => 'Priya Nair', 'avatar_path' => null],
                (object) ['name' => 'Tom Ba', 'avatar_path' => null],
                (object) ['name' => 'Ana Ruiz', 'avatar_path' => null],
            ])" :max="4" size="md" />
        </div>
    </section>

    <section class="grid gap-4 sm:grid-cols-2">
        <x-ui.card title="Website Redesign" subtitle="Due 24 September · 8 members">
            <x-slot:actions>
                <x-ui.badge color="amber" dot>At risk</x-ui.badge>
            </x-slot:actions>
            <div class="space-y-3">
                <x-ui.progress :value="68" show-label />
                <div class="grid grid-cols-4 gap-3 text-center">
                    @foreach ([['47', 'Tasks'], ['31', 'Done'], ['3', 'Overdue'], ['6', 'Milestones']] as [$n, $l])
                        <div>
                            <p class="text-lg font-semibold tabular-nums text-[var(--text-strong)]">{{ $n }}</p>
                            <p class="text-2xs uppercase tracking-wide text-[var(--text-muted)]">{{ $l }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="Form controls">
            <div class="space-y-3">
                <x-ui.field label="Task title" required>
                    <x-ui.input placeholder="Prepare the campaign landing page" />
                </x-ui.field>
                <x-ui.field label="Project" hint="Only projects you can access are listed.">
                    <x-ui.select :options="['1' => 'Website Redesign', '2' => 'Marketing Campaign']" />
                </x-ui.field>
                <x-ui.field label="Due date" error="Choose a date on or after the start date.">
                    <x-ui.input type="date" invalid />
                </x-ui.field>
                <x-ui.checkbox label="Notify the assignee" description="Sends an in-app and email notification." />
            </div>
        </x-ui.card>
    </section>

    <section>
        <h2 class="mb-3 text-sm font-semibold text-[var(--text-strong)]">Tabs</h2>
        <x-ui.tabs>
            <x-ui.tab :active="true" icon="icon.home">Overview</x-ui.tab>
            <x-ui.tab icon="icon.list" :count="47">Tasks</x-ui.tab>
            <x-ui.tab icon="icon.board">Board</x-ui.tab>
            <x-ui.tab icon="icon.timeline">Timeline</x-ui.tab>
            <x-ui.tab icon="icon.calendar">Calendar</x-ui.tab>
            <x-ui.tab icon="icon.document">Wiki</x-ui.tab>
            <x-ui.tab icon="icon.sparkles">AI</x-ui.tab>
        </x-ui.tabs>
    </section>

    <section>
        <h2 class="mb-3 text-sm font-semibold text-[var(--text-strong)]">Empty state</h2>
        <x-ui.card flush>
            <x-ui.empty-state icon="icon.folder" title="No projects yet"
                              description="Create your first project to start organising your work, or describe it and let Planvio AI build the structure for you.">
                <x-slot:actions>
                    <x-ui.button variant="primary" icon="icon.plus">Create project</x-ui.button>
                    <x-ui.button variant="secondary" icon="icon.sparkles">Create with AI</x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>
    </section>

    <section>
        <h2 class="mb-3 text-sm font-semibold text-[var(--text-strong)]">Icons</h2>
        <div class="grid grid-cols-8 gap-3 rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] p-4 sm:grid-cols-12">
            @foreach (collect(glob(resource_path('views/components/icon/*.blade.php')))->map(fn ($p) => basename($p, '.blade.php')) as $name)
                <div class="flex flex-col items-center gap-1 text-[var(--text-muted)]">
                    <x-dynamic-component :component="'icon.'.$name" class="size-5" />
                    <span class="truncate text-[9px]">{{ $name }}</span>
                </div>
            @endforeach
        </div>
    </section>

</div>
</body>
</html>
