# Developing Planvio

This is for people working **on** Planvio. If you are installing it, read
[CPANEL.md](CPANEL.md) or [DEPLOYMENT.md](DEPLOYMENT.md) instead.

Before you write a line of code, read [ARCHITECTURE.md](ARCHITECTURE.md). It is normative:
every class name, table, column, enum case and namespace in it is fixed. This document
tells you how to work inside that contract, not how to redesign it.

---

## What you need

| | |
|---|---|
| PHP | 8.3 or newer. The reference development machine runs 8.4.25 |
| Extensions | `pdo` `pdo_mysql` `mbstring` `openssl` `json` `fileinfo` `xml` `ctype` `tokenizer` `curl` `bcmath` `gd` `zip` |
| Composer | 2.x |
| Node | 20 or newer (22 on the reference machine), npm 10+ to install, npm 11+ to change `package-lock.json` — see [below](#package-lockjson-is-written-with-npm-11) |
| Database | MySQL 8 / MariaDB 10.6+, or SQLite for a quick local run |
| Disk | ~1.5 GB with `vendor/` and `node_modules/` |

Node and Composer are **development-only**. Production never runs either — see
[Building a release](#building-a-release).

The extension list is not arbitrary: it is exactly
`config/planvio.php → install.required_extensions` plus `zip`, which
`scripts/build-release.php` needs to package a release.

---

## If `php` is not on your PATH

Every command in this guide is written as plain `php` and `composer`, which is what you get
from Homebrew, apt, Herd, Laragon, XAMPP or an official Windows build.

If yours lives somewhere the shell cannot find, either add it to `PATH` or keep a small
wrapper of your own at the repository root:

```bash
# ./php
#!/usr/bin/env bash
exec /path/to/your/php "$@"
```

`/php` and `/composer` are in `.gitignore` precisely so a local shim like that never reaches
a commit — it is yours, it is not part of the product, and it is meaningless on somebody
else's machine. `scripts/build-release.php` excludes them from the release archive for the
same reason.

Scripts under `vendor/bin` carry a `#!/usr/bin/env php` shebang, so if PHP is not on `PATH`
you will need to invoke them through the interpreter:

```bash
php vendor/bin/pint
php vendor/bin/phpunit --testsuite=Security
```
---

## Setting up from a clone

1. **Install PHP dependencies.**

   ```bash
   composer install
   ```

2. **Install frontend dependencies.**

   ```bash
   npm ci
   ```

   `ci`, not `install`: it installs exactly what `package-lock.json` says and never writes
   to it, whichever npm you have. See
   [`package-lock.json` is written with npm 11](#package-lockjson-is-written-with-npm-11).

3. **Create your `.env`.**

   ```bash
   cp .env.example .env
   ```

   `.env.example` is written for production. Change these for local work:

   ```
   APP_ENV=local
   APP_DEBUG=true
   APP_URL=http://localhost:8000
   APP_INSTALLED=true
   SESSION_SECURE_COOKIE=false
   LOG_LEVEL=debug
   MAIL_MAILER=log
   ```

   `APP_INSTALLED=true` tells Planvio the first-run wizard has already been satisfied, so
   it does not intercept your requests. In production only the installer writes that key.

   For SQLite, set `DB_CONNECTION=sqlite` and delete the other `DB_*` lines; Laravel then
   uses `database/database.sqlite`. For MySQL, keep `DB_CONNECTION=mysql` and fill in
   `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`.

4. **Generate the application key.**

   ```bash
   php artisan key:generate
   ```

   This key encrypts `ai_providers.api_key`, `users.two_factor_secret` and
   `users.two_factor_recovery_codes`. Rotating it makes every existing encrypted value
   unreadable. Never share a `.env` between environments.

5. **Run the migrations.**

   ```bash
   php artisan migrate
   ```

6. **Seed.**

   ```bash
   php artisan db:seed
   ```

7. **Start the dev processes.**

   ```bash
   php artisan dev
   ```

   Laravel 13's `dev` command runs the PHP server, the queue worker, the log tailer and
   Vite together. On Windows it multiplexes through `concurrently`; elsewhere through
   `@laravel/multiplex`. Both are already in `package.json`.

   If you would rather run them yourself, two terminals:

   ```bash
   php artisan serve
   npm run dev
   ```

`composer run setup` does steps 1–3, 5 and a production asset build in one go. It is
convenient for a fresh machine and useless afterwards.

---

## Everyday commands

| Task | Command |
|---|---|
| Run the app | `php artisan dev` |
| Run one migration batch | `php artisan migrate` |
| Roll back the last batch | `php artisan migrate:rollback` |
| Rebuild a scratch database | `php artisan migrate:fresh --seed` |
| Tinker | `php artisan tinker` |
| Tail logs | `php artisan pail` |
| Run every test | `php artisan test` |
| Run one suite | `php artisan test --testsuite=Security` |
| Format | `php vendor/bin/pint` |
| Check formatting only | `php vendor/bin/pint --test` |
| Build a release | `php scripts/build-release.php` |

`migrate:fresh` and `db:wipe` destroy data. Never run either against a database you did not
create yourself.

---

## Directory layout

This is `ARCHITECTURE.md` §2. It is not a suggestion — if a class does not have an obvious
home here, you are probably about to build the wrong thing.

```
app/
  Actions/<Domain>/<VerbNoun>.php      Write operations. Invokable. Transactional.
  Ai/
    Contracts/                          AiProvider, AiTool, ContextProvider
    Providers/                          OpenAiCompatibleProvider, AnthropicProvider, ...
    Agent/                              AgentRunner, AgentContext, ToolRegistry,
                                        ToolCall, ToolResult, RunLimits
    Tools/<Group>/<ToolName>Tool.php    One class per tool
    Context/                            Context builder services
    Support/                            Redactor, TokenEstimator, PromptBuilder,
                                        RelativeDateParser
  Enums/                                Backed enums, string-backed unless stated
  Http/
    Controllers/                        Thin. Auth, files, API, installer, webhooks
    Middleware/
    Requests/
    Resources/                          API JSON resources
  Livewire/
    App/<Area>/<Component>.php          Product UI
    Installer/<Step>.php
  Models/
  Notifications/
  Policies/
  Services/                             Read models, query services, domain calculators
  Support/                              Settings, Branding, Version, Health, Permissions
database/migrations/                    All schema. Never change schema at runtime.
resources/views/app/                    Product Blade
resources/views/installer/
resources/views/components/             Blade components (the design system)
```

### What belongs in each layer

| Layer | Does | Never does |
|---|---|---|
| **Actions** | One write operation. Invokable (`__invoke`). Wraps itself in a transaction. Validates domain invariants. Emits an activity row. | Authorize. Read the request. Render anything. |
| **Services** | Read-side queries, aggregations, domain calculations. | Write. Dispatch jobs. Send mail. Any side effect at all. |
| **Policies** | Resolve workspace membership, check the role permission, refine by project role or record ownership. Return a boolean. | Mutate. Query outside the workspace. Trust a global scope. |
| **Livewire** | Bind input, call `authorize()`, call an Action or a Service, render. | Contain domain rules. Build queries with business logic in them. |
| **Ai/** | Describe tools to the model, validate tool input, check the Gate as the acting user, delegate to Actions. | Touch the database directly. Build SQL. Reach the filesystem or shell. |

The rule that makes this hold together: **Actions never authorize; callers do.** An Action
is reachable from a Livewire component, a controller, a job and an AI tool. If it
authorized internally it would need to guess which of those it is running under. Instead,
every caller runs the Gate check itself, and the Action can assume the decision was made.

---

## Conventions

**Every PHP file starts with strict types.**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Tasks;
```

Pint adds it for you (`declare_strict_types` is on in `pint.json`), but write it anyway.

**Classes are `final` unless designed for extension.** Pint will *not* add it —
`final_class` is deliberately `false` in `pint.json`, because a blanket rule would seal
classes that are meant to be extended. Decide per class and write it by hand.

**Constructor property promotion, typed everywhere.**

```php
final class CreateTask
{
    public function __construct(
        private readonly TaskNumberAllocator $numbers,
        private readonly ActivityRecorder $activity,
    ) {}
}
```

**Casts go in the `casts()` method, not a `$casts` property.**

```php
protected function casts(): array
{
    return [
        'priority' => Priority::class,
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'settings' => 'array',
        'api_key' => 'encrypted',
    ];
}
```

**Mass assignment is always an allow-list.** Never `$guarded = []`. Laravel 13 accepts
either form; `App\Models\User` uses the attribute, so match whichever the file next to
yours uses:

```php
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
```

```php
protected $fillable = ['title', 'description', 'priority', 'due_date'];
```

`workspace_id` is **never** fillable. It is set by the `BelongsToWorkspace` trait from the
bound workspace, or explicitly by an Action. A fillable `workspace_id` is a tenant leak
waiting for a mass-assignment payload.

**Every user-visible string goes through `__()`.** Keys live in `lang/en/*.php`.

```php
// No.
return ToolResult::fail('That task is not in this workspace.');

// Yes.
return ToolResult::fail(__('ai.errors.task_not_in_workspace'));
```

That includes enum labels. Every enum implements `label()` and reads from
`lang/en/enums.php`:

```php
public function label(): string
{
    return __('enums.ai_tool_risk.'.$this->value);
}
```

**Money is `decimal(15,2)`. Durations are integer minutes. Dates are `date`, instants are
`timestamp` in UTC.** No floats for money, no "hours" columns, no local time in the
database.

---

## Multi-tenancy: the rules you must never break

Three layers, all independent. A failure in one must not produce a leak.

| Layer | What it is | What it guarantees |
|---|---|---|
| **Column** | Every tenant-scoped table has `workspace_id`, indexed, `cascadeOnDelete` | The data can always be attributed to one tenant |
| **Scope** | `BelongsToWorkspace` registers `WorkspaceScope`, which filters on the workspace bound to `App\Support\CurrentWorkspace` | Ordinary queries cannot accidentally cross tenants |
| **Policy** | Every policy method re-resolves `WorkspaceMember` for `$user` and `$model->workspace_id`, independently of the scope | Authorisation does not depend on the scope having been applied |

The critical property of `WorkspaceScope` is that **it is inert when nothing is bound**:

```php
public function apply(Builder $builder, Model $model): void
{
    $workspaceId = Container::getInstance()->make(CurrentWorkspace::class)->id();

    if ($workspaceId === null) {
        return;
    }

    $builder->where($model->qualifyColumn('workspace_id'), $workspaceId);
}
```

That is intentional — the installer, console commands and the Filament admin panel need to
work across tenants. It is also the sharpest edge in the codebase: **anything that runs
outside an HTTP request has no workspace bound, so the scope does nothing.** Queued jobs,
scheduled commands and AI runs must bind one explicitly.

### The four rules

1. Never take a `workspace_id` from user input. It comes from the route binding via
   `SetCurrentWorkspace`, or from an explicit `runFor()`.
2. Never rely on the scope for security. It is convenience. The policy is the authority.
3. Always bind a workspace before querying tenant data outside an HTTP request.
4. `withoutWorkspaceScope()` is for admin and system code only, and every call site needs a
   comment saying why.

### Wrong and right, side by side

**Reading a record.**

```php
// WRONG — bypasses the scope and never asks whether this user may see it.
// A user in workspace A can read a task in workspace B by guessing an id.
public function show(int $taskId): View
{
    $task = Task::withoutWorkspaceScope()->findOrFail($taskId);

    return view('app.tasks.show', ['task' => $task]);
}
```

```php
// RIGHT — the scope narrows the lookup, the policy decides.
// TaskPolicy::view() re-resolves WorkspaceMember for $user and $task->workspace_id,
// so this is still safe if the scope is ever inert.
public function show(Task $task): View
{
    $this->authorize('view', $task);

    return view('app.tasks.show', ['task' => $task]);
}
```

**Working inside a job.**

```php
// WRONG — nothing is bound, so WorkspaceScope is inert.
// This iterates the due tasks of every tenant on the server and emails the wrong people.
public function handle(): void
{
    foreach (Task::query()->whereDate('due_date', today())->cursor() as $task) {
        $task->assignee?->notify(new TaskDueSoon($task));
    }
}
```

```php
// RIGHT — bind the tenant for the duration of the work, then restore what was bound.
public function handle(CurrentWorkspace $current): void
{
    $current->runFor($this->workspace, function (): void {
        foreach (Task::query()->whereDate('due_date', today())->cursor() as $task) {
            $task->assignee?->notify(new TaskDueSoon($task));
        }
    });
}
```

**Writing a policy.**

```php
// WRONG — trusts that the scope already filtered the model.
// If this is ever called from a job or an AI run, it grants across tenants.
public function update(User $user, Task $task): bool
{
    return Permissions::has($user->workspaceRole(), Permission::TaskUpdate);
}
```

```php
// RIGHT — resolve membership from the model's own workspace_id, then check the matrix,
// then refine by project role where the matrix marks the cell conditional.
public function update(User $user, Task $task): bool
{
    $member = WorkspaceMember::query()
        ->where('workspace_id', $task->workspace_id)
        ->where('user_id', $user->id)
        ->first();

    if ($member === null) {
        return false;
    }

    if (! Permissions::has($member->role, Permission::TaskUpdate)) {
        return false;
    }

    if (Permissions::ownOnly($member->role, Permission::TaskUpdate)) {
        return $task->assignee_id === $user->id || $task->reporter_id === $user->id;
    }

    return true;
}
```

Cross-workspace access is a security bug, not a bug report. It gets a test in
`tests/Feature/Security/` before it gets a fix.

---

## How to add things

### A new migration

```bash
php artisan make:migration create_task_dependencies_table
```

Then match `ARCHITECTURE.md` §5 exactly — column names, types, nullability, indexes,
uniques. Conventions that apply to every tenant-scoped table:

```php
Schema::create('task_dependencies', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
    $table->foreignId('task_id')->constrained()->cascadeOnDelete();
    $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
    $table->string('type', 32)->default(DependencyType::FinishToStart->value);
    $table->timestamps();

    $table->unique(['task_id', 'depends_on_task_id']);
});
```

Rules: `workspace_id` first among the foreign keys, every FK indexed, `decimal(15,2)` for
money, unsigned integers for minutes, `softDeletes()` only where §5 says so. Enums are
stored as their string value in a `string` column — never a MySQL `ENUM`, which cannot be
extended without an `ALTER TABLE`.

Schema changes only ever happen in a migration. Nothing in the application modifies schema
at runtime.

### A new enum

Put it in `app/Enums`, back it with `string`, and give it `label()`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum DependencyType: string
{
    case FinishToStart = 'finish_to_start';
    case Blocks = 'blocks';
    case RelatesTo = 'relates_to';

    public function label(): string
    {
        return __('enums.dependency_type.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::FinishToStart => 'blue',
            self::Blocks => 'red',
            self::RelatesTo => 'gray',
        };
    }
}
```

Add the labels to `lang/en/enums.php` in the same commit. If the enum is shown in the UI,
add `color()` and `icon()` (a Heroicon name) too — `AiToolRisk` is the reference
implementation, including its ordered `level()`, `atLeast()` and `exceeds()` helpers.

The enum cases in §6 are the complete set. Adding a case is an amendment to
`ARCHITECTURE.md`, not a local decision.

### A new model

1. Write the migration first.
2. Create the model in `app/Models`.
3. Add `use BelongsToWorkspace;` if the table has `workspace_id`.
4. Declare `casts()` and the fillable allow-list.
5. Write the policy in the same commit.
6. Write the cross-workspace isolation test in the same commit.

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DependencyType;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TaskDependency extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['task_id', 'depends_on_task_id', 'type'];

    protected function casts(): array
    {
        return ['type' => DependencyType::class];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
```

The trait gives you three things: the `WorkspaceScope` global scope, a `creating` hook that
back-fills `workspace_id` from `CurrentWorkspace`, and the `forWorkspace()` /
`withoutWorkspaceScope()` scopes.

### A new Action

One file, one write operation, in `app/Actions/<Domain>/<VerbNoun>.php`.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AssignTask
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    public function __invoke(Task $task, ?User $assignee, User $actor): Task
    {
        // Domain invariant, not authorization: an assignee must be in the workspace.
        if ($assignee !== null && ! $task->workspace->hasMember($assignee)) {
            throw new AssigneeNotInWorkspace($task, $assignee);
        }

        return DB::transaction(function () use ($task, $assignee, $actor): Task {
            $previous = $task->assignee_id;

            $task->assignee_id = $assignee?->id;
            $task->save();

            $this->activity->record($task, 'assigned', $actor, [
                'attribute' => 'assignee_id',
                'old' => $previous,
                'new' => $task->assignee_id,
            ]);

            return $task->refresh();
        });
    }
}
```

Checklist for every Action:

- [ ] `final`, invokable, one public method
- [ ] Wrapped in `DB::transaction()`
- [ ] Validates domain invariants, throws a typed exception when they fail
- [ ] Records an activity row
- [ ] Contains **no** `Gate::`, `authorize()`, `->can()` or `$request`
- [ ] Returns the affected model, not a boolean

### A new policy

One policy per model, in `app/Policies`. Every method does the same three things in the
same order: membership, matrix, refinement. Use `App\Support\Permissions` for the matrix —
it is static, has no database access, and mirrors §4.2 exactly:

| Helper | Answers |
|---|---|
| `Permissions::for(WorkspaceRole)` | Every permission this role holds |
| `Permissions::has(WorkspaceRole, Permission)` | Does this role hold it at all? |
| `Permissions::requiresProjectScope(WorkspaceRole, Permission)` | Is the cell `+` or `*`, i.e. does it need a `ProjectMember` check? |
| `Permissions::ownOnly(WorkspaceRole, Permission)` | Is the cell `~` or `own`, i.e. limited to the user's own records? |
| `Permissions::projectScoped(WorkspaceRole)` | Every conditional permission for this role |

Do not re-implement the matrix in a policy. If a cell looks wrong, fix `ARCHITECTURE.md`
§4.2 and `Permissions::MATRIX` together.

### A new Livewire page

Component in `app/Livewire/App/<Area>/<Name>.php`, view in
`resources/views/livewire/app/<area>/<name>.blade.php`. The pairing is mandatory.

```php
<?php

declare(strict_types=1);

namespace App\Livewire\App\Tasks;

use App\Actions\Tasks\AssignTask;
use App\Models\Task;
use App\Models\User;
use Livewire\Component;

final class TaskDetail extends Component
{
    public Task $task;

    public function mount(Task $task): void
    {
        $this->authorize('view', $task);

        $this->task = $task;
    }

    public function assign(?int $userId, AssignTask $assignTask): void
    {
        // The component authorizes. The Action does not.
        $this->authorize('assign', $this->task);

        $assignee = $userId === null ? null : User::query()->findOrFail($userId);

        $this->task = $assignTask($this->task, $assignee, auth()->user());

        $this->dispatch('task-updated', taskId: $this->task->id);
    }

    public function render(): View
    {
        return view('livewire.app.tasks.task-detail');
    }
}
```

Two things people get wrong: authorising in `render()` instead of `mount()` and every
action method, and pushing a query with business rules in it into the Blade view. Put the
query in a Service.

Product UI is Livewire 4 + Blade + Tailwind 4 and must not look like an admin panel.
`/admin` is Filament and is for system administration only.

### A new AI tool

This is the least obvious one, so here it is end to end.

An AI tool is the only way the model can change anything. It is a thin, declarative
wrapper: it describes itself to the model, validates the model's arguments, checks the Gate
**as the acting user**, asserts the workspace boundary, and then calls an Action. It never
touches the database itself.

The interface is fixed (`ARCHITECTURE.md` §7.2):

```php
interface AiTool {
    public function name(): string;             // snake_case, must be in the §7.4 list
    public function group(): string;
    public function description(): string;      // shown to the model
    public function parameters(): array;        // JSON Schema (object)
    public function risk(): AiToolRisk;
    public function permission(): ?Permission;  // checked via Gate as the acting user
    public function isMutating(): bool;
    public function execute(array $args, AgentContext $ctx): ToolResult;
}
```

#### Worked example: `assign_task`

**Step 1 — check the tool is in the contract.** `assign_task` appears in §7.4 under
*medium* risk. A tool that is not in that list needs an amendment to `ARCHITECTURE.md`
first, agreed as a change to the product's capability surface. Do not add one quietly.

**Step 2 — make sure the Action exists.** `App\Actions\Tasks\AssignTask`, above. If it does
not exist, write it first and test it on its own. The tool must add no domain logic that
the Livewire path does not already have, or the two surfaces will drift.

**Step 3 — write the tool** in `app/Ai/Tools/Tasks/AssignTaskTool.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ai\Tools\Tasks;

use App\Actions\Tasks\AssignTask;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkspaceMember;

final class AssignTaskTool implements AiTool
{
    public function __construct(private readonly AssignTask $assignTask) {}

    public function name(): string
    {
        return 'assign_task';
    }

    public function group(): string
    {
        return 'tasks';
    }

    public function description(): string
    {
        return __('ai.tools.assign_task.description');
    }

    /** @return array<string, mixed> */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => [
                    'type' => 'integer',
                    'description' => 'Planvio task id, as returned by search_tasks or get_task.',
                ],
                'assignee_id' => [
                    'type' => ['integer', 'null'],
                    'description' => 'User id of a workspace member, or null to unassign.',
                ],
            ],
            'required' => ['task_id'],
            'additionalProperties' => false,
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Medium;
    }

    public function permission(): ?Permission
    {
        return Permission::TaskAssign;
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        $task = Task::query()->find((int) $args['task_id']);

        // Boundary assertion. Never assume the scope was applied for us.
        if ($task === null || $task->workspace_id !== $ctx->workspace->id) {
            return ToolResult::fail(__('ai.errors.task_not_in_workspace'));
        }

        // Gate check as the acting user. There is no system bypass.
        if (! $ctx->can(Permission::TaskAssign, $task)) {
            return ToolResult::fail(__('ai.errors.not_permitted'));
        }

        $assignee = null;

        if (($args['assignee_id'] ?? null) !== null) {
            $isMember = WorkspaceMember::query()
                ->where('workspace_id', $ctx->workspace->id)
                ->where('user_id', (int) $args['assignee_id'])
                ->exists();

            if (! $isMember) {
                return ToolResult::fail(__('ai.errors.assignee_not_in_workspace'));
            }

            $assignee = User::query()->find((int) $args['assignee_id']);
        }

        $task = ($this->assignTask)($task, $assignee, $ctx->user);

        return ToolResult::ok(
            data: ['task_id' => $task->id, 'assignee_id' => $task->assignee_id],
            summary: __('ai.tools.assign_task.summary', [
                'task' => $task->title,
                'assignee' => $assignee?->name ?? __('ai.tools.assign_task.nobody'),
            ]),
            subject: $task,
        );
    }
}
```

**Step 4 — add the translation keys** to `lang/en/ai.php` (create the file if it does not
exist yet). The `description()` string is read by the model, so write it for the model:
say what the tool does, what the arguments mean, and when *not* to use it.

**Step 5 — register it** with `App\Ai\Agent\ToolRegistry`. The registry is the only place
that maps a tool name to a class; the agent loop resolves through it and nothing else.

**Step 6 — decide the approval story.** `config/ai.php` already governs this:

```php
'approvals' => [
    'auto_execute_max_risk' => [
        'assistant' => null,        // assistant mode executes nothing that mutates
        'copilot' => 'read',        // reads run freely, every mutation is confirmed
        'autonomous' => 'medium',   // up to medium risk runs unattended
    ],
    'always_require_approval' => [
        'delete_project', 'delete_task', 'remove_workspace_member', 'archive_project',
    ],
],
```

`assign_task` is `medium`, so it runs unattended in autonomous mode and is confirmed in
copilot mode. If your tool must always be confirmed regardless of workspace policy, add
its name to `always_require_approval` — a workspace policy cannot waive that list.

**Step 7 — test it.** Four tests, minimum:

| Test | Suite | Asserts |
|---|---|---|
| Schema and metadata | `tests/Unit` | `name()` matches the §7.4 list, `parameters()` is a valid JSON Schema object, `risk()` and `permission()` are what you intended |
| Happy path | `tests/Feature` | A user with `task.assign` assigns, the task changes, an `ai_tool_runs` row is written |
| Permission denial | `tests/Feature/Security` | A `member` (who does not hold `task.assign`) gets a failed `ToolResult`, and the task is unchanged |
| Cross-workspace | `tests/Feature/Security` | A `task_id` from another workspace returns a failure, never the record |

The last two matter most. A tool that returns "not permitted" is the system working; a tool
that returns another tenant's data is a breach.

#### Things that will get an AI tool rejected in review

- Querying or writing without going through an Action
- Calling `Gate::forUser()` with anything other than `$ctx->user`
- Using `withoutWorkspaceScope()`
- Trusting `$args` without validating against `parameters()`
- Returning raw model attributes instead of a bounded `data` array
- Interpolating workspace text into a prompt anywhere but `PromptBuilder`, which wraps it
  in `<untrusted-data>`

---

## Testing

Planvio uses **PHPUnit 12**, not Pest. Tests are plain classes extending `Tests\TestCase`.

`phpunit.xml` defines four suites, and the split is deliberate:

| Suite | Directory | Purpose |
|---|---|---|
| `Unit` | `tests/Unit` | No database, no HTTP. Enums, value objects, the permission matrix, calculators |
| `Feature` | `tests/Feature` (excluding `Security` and `Installer`) | The application working end to end |
| `Security` | `tests/Feature/Security` | Isolation, authorization and AI-permission tests. **Every test in it asserts that something is NOT possible** |
| `Installer` | `tests/Feature/Installer` | Runs last. These manipulate the install lock and `.env`, so they never interleave with the rest |

### Running them

```bash
php artisan test                              # everything
php artisan test --testsuite=Security         # just the isolation suite
php artisan test --filter=TaskWorkspaceIsolationTest
php artisan test --parallel                   # faster, needs the sqlite driver
php vendor/bin/phpunit --testsuite=Unit       # straight PHPUnit
composer run test                             # clears config first, then runs
```

`composer run test` clears the config cache before running. Use it if you have ever run
`config:cache` locally — a stale cached config silently overrides `phpunit.xml`.

### The test environment

`phpunit.xml` sets it, so tests never touch your development database or the network:

| | |
|---|---|
| `DB_CONNECTION` | `sqlite` |
| `DB_DATABASE` | `:memory:` |
| `CACHE_STORE` / `SESSION_DRIVER` | `array` |
| `QUEUE_CONNECTION` | `sync` |
| `MAIL_MAILER` | `array` |
| `BCRYPT_ROUNDS` | `4` |
| `AI_ENABLED` | `true` |
| `AI_AUTOMATIONS_ENABLED` | `false` |

AI is deliberately **on** in tests so the tool registry, policy engine and agent loop are
exercised on every run. No provider is configured, so any test that reaches the network
must fake the HTTP client. **A real outbound request in the suite is a bug**, not a slow
test.

That `true` is a default rather than a guarantee. An `<env>` entry without `force="true"`
gives way to a variable already set in the process environment, so an `AI_ENABLED` exported
in your shell or by a CI runner wins — and Planvio itself ships with AI off. A test whose
outcome depends on the master switch therefore sets it itself, with
`config(['ai.enabled' => true])` (or `false`, for the switched-off path), rather than
inheriting it. CI runs the whole suite a second time with AI switched off to hold every test
to that; to do the same locally:

```bash
AI_ENABLED=false php artisan test
```

### The isolation rule

> Every new tenant-scoped resource needs a cross-workspace isolation test in
> `tests/Feature/Security/`, in the same commit that introduces the resource.

Not "eventually", not "when we harden it". The same commit. A resource without one is
incomplete, and reviewers should say so.

The test has two halves, and the second is the one that catches real bugs:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\WorkspaceRole;
use App\Models\Task;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TaskWorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_member_of_one_workspace_cannot_reach_a_task_in_another(): void
    {
        $alpha = $this->makeWorkspace();
        $alphaOwner = $this->makeMember($alpha, WorkspaceRole::Owner);
        $task = $this->makeTask($this->makeProject($this->makeWorkspace()));

        // Looked up through Alpha, the id does not exist: a 404, not a 403 that confirms it.
        $this->actingAs($alphaOwner)
            ->get(route('app.tasks.show', [$alpha, $task]))
            ->assertNotFound();
    }

    #[Test]
    public function the_policy_denies_even_when_no_workspace_is_bound(): void
    {
        $alphaOwner = $this->makeMember($this->makeWorkspace(), WorkspaceRole::Owner);
        $task = $this->makeTask($this->makeProject($this->makeWorkspace()));

        // Nothing bound, so WorkspaceScope is inert. The policy must still say no.
        app(CurrentWorkspace::class)->forget();

        $this->assertFalse($alphaOwner->can('view', $task));
        $this->assertFalse($alphaOwner->can('update', $task));
        $this->assertFalse($alphaOwner->can('delete', $task));
    }

    #[Test]
    public function the_scope_hides_other_workspaces_when_one_is_bound(): void
    {
        $alpha = $this->makeWorkspace();
        $beta = $this->makeWorkspace();

        $alphaProject = $this->makeProject($alpha);
        $betaProject = $this->makeProject($beta);

        foreach (range(1, 2) as $i) {
            $this->makeTask($alphaProject);
        }
        foreach (range(1, 3) as $i) {
            $this->makeTask($betaProject);
        }

        app(CurrentWorkspace::class)->runFor($alpha, function (): void {
            $this->assertSame(2, Task::query()->count());
        });
    }
}
```

The second test is the important one. It removes the scope from the equation entirely and
proves the policy stands on its own. If a policy only passes with a workspace bound, the
policy is wrong.

---

## Code style

```bash
php vendor/bin/pint          # format
php vendor/bin/pint --test   # check only, non-zero exit on a diff
php vendor/bin/pint --dirty  # only files you have changed
```

`pint.json` is the Laravel preset plus:

| Rule | Effect |
|---|---|
| `declare_strict_types` | Adds `declare(strict_types=1);` |
| `final_class: false` | Pint will **not** seal classes for you. You add `final` by hand |
| `ordered_imports` (alpha) | Imports sorted alphabetically |
| `no_unused_imports` | Removes dead imports |
| `not_operator_with_successor_space` | `! $ok`, not `!$ok` |
| `trailing_comma_in_multiline` | Arrays, arguments and parameters |
| `nullable_type_declaration_for_default_null_value` | `?Foo $bar = null` |

`vendor`, `node_modules`, `storage`, `bootstrap/cache`, `build` and `dist` are excluded.

Run Pint before you claim a change is done, along with the test suite:

```bash
php artisan test
php vendor/bin/pint --test
```

There is no static analyser configured. If you add one, it goes in `require-dev` and it
must not become a production dependency.

---

## The wiki is a different repository

`wiki/` in the working tree holds the GitHub wiki's pages. It is **not** part of the code
repository — GitHub wikis live at `<repo>.wiki.git`, a separate repository with its own
history — so both build scripts skip the directory and it never reaches either the public
repo or the release ZIP.

To publish a change to it:

```bash
git clone https://github.com/hatemsweileh/planvio.wiki.git /tmp/planvio-wiki
cp wiki/*.md /tmp/planvio-wiki/
cd /tmp/planvio-wiki && git add -A && git commit -m "Update wiki" && git push
```

The wiki has to be enabled once in **Settings → Features → Wikis** before that remote
exists, and the first page has to be created through the web interface — GitHub does not
create the wiki repository until there is something in it.

What goes where matters more than it looks. `docs/` is versioned with the code, so a change
in behaviour changes the doc in the same commit and the pair is always true together. The
wiki is not versioned and anyone can edit it, which makes it right for what is learned after
a release — a host with an odd PHP path, a symptom nobody predicted — and wrong for anything
that must stay in step with a particular version. **If it would have to change when the code
changes, it belongs in `docs/`.**

---

## Dependencies are resolved for PHP 8.3, whatever you are running

`composer.json` pins the resolver's idea of the platform:

```json
"config": {
    "platform": { "php": "8.3.0" }
}
```

Without it, Composer resolves against *your* PHP, so a `composer update` run on 8.4
happily selects packages that require 8.4 — Symfony 8 requires `>=8.4.1`, and Laravel 13
accepts either Symfony 7 or 8. The lock file that came out of that machine then cannot be
installed on 8.3 at all, while `composer.json` and the README both still promise it.

For Planvio that is worse than an inconvenience, because **the release archive ships
`vendor/`**. A customer on cPanel with PHP 8.3 selected does not run Composer and so never
sees a resolution error; they get a prebuilt tree of 8.4-only code and a fatal error at
runtime, on a host where they cannot fix it.

So the lock is always resolved for the oldest PHP the product supports. Consequences worth
knowing:

- `composer update` on PHP 8.4 gives you the same versions it gives everyone else. That is
  the point — the lock is a shared artefact, not a local one.
- Composer will never select a package requiring 8.4+, even if you are on 8.4. To use one,
  first raise `require.php` **and** this pin **and** the CI matrix **and** the README
  together. Doing fewer than all four leaves the product claiming support it does not have.
- `composer check-platform-reqs` reports against the PHP actually running, not against the
  pin, so it cannot answer this question. `composer why-not php 8.3` can.

---

## `package-lock.json` is written with npm 11

Install with `npm ci`. Change the lock only with npm 11 or newer.

Tailwind, Lightning CSS and Rolldown each ship their native code as a set of optional
packages, one per operating system, CPU and C library, and the lock tells them apart by
their `os`, `cpu` and `libc` fields. npm 11 records `libc`. npm 10 — the one Node 22 comes
with — drops the field from every entry whenever it rewrites the lock, and a plain
`npm install` rewrites it. Two things follow from a lock without it:

- npm can no longer tell a glibc build from a musl one, so it installs both on every Linux
  machine, CI included: on x64, about 22 MB of native binaries that nothing loads.
- The lock stops being stable. An npm 10 run strips the field from every entry; npm 11 only
  puts it back on the entries it happens to resolve again, so the lock ends up half one and
  half the other, and a one-line dependency change arrives buried in dozens of lines nobody
  meant to change.

So:

- **Installing** — `npm ci`. It installs exactly what the lock says, on npm 10 or 11, and
  never writes to it. CI installs the same way, and so does `scripts/build-release.php`
  unless `npm ci` fails: it then falls back to `npm install`, and on npm 10 the lock it
  leaves behind has lost the field. Redo or repair it as below rather than commit it.
- **Adding, removing or updating a package** — use npm 11. `npx npm@11 install <package>`
  does it without replacing the npm your Node came with. Dependabot's lock updates already
  carry the field.
- **Reviewing** — a diff to `package-lock.json` that removes `"libc"` lines was written by
  npm 10. Make the change again with npm 11, starting from the lock before it, rather than
  merging it.
- **Repairing** — npm 11 does not put the field back on an entry it already has, so a lock
  that has lost it stays that way through any number of npm 11 runs. Delete the affected
  entries from `package-lock.json` and run `npx npm@11 install --package-lock-only`: they
  are resolved again at the exact versions their parent packages pin, this time with
  `libc`, and nothing else in the lock moves.

---

## Building a release

```bash
php scripts/build-release.php
```

Output: `dist/planvio-v1.0.0.zip` — the version comes from
`config/planvio.php → version`.

### What it does, in order

1. **Preflight** — checks that the script sits in a Planvio checkout (`composer.json` one
   level above `scripts/`) and that the `zip` extension is loaded. It works out the
   repository from its own location, so you can run it from any directory.
2. **Frontend assets** — runs `npm ci` (falling back to `npm install`) and `npm run build`,
   then refuses to continue unless `public/build/manifest.json` exists. A release without
   compiled assets is useless: production has no Node. The stylesheet comes out the same
   whether or not your working tree has a `vendor/`: Tailwind reads only the sources
   `resources/css/app.css` lists, and none of them is under `vendor/`, so a release built
   from a fresh clone ships the CSS CI built and tested.
3. **Staging** — copies the tree into `build/staging`. It copies from the filesystem rather
   than from git, because the release needs files git does not have — `public/build/` —
   and it decides what to leave out in two layers.

   **`.gitignore`, as git reads it.** When the repository is a git work tree that tracks
   `composer.json` and `git` is on your `PATH`, the builder first applies the explicit
   rules below, then hands every remaining file and directory to a single
   `git check-ignore --stdin -z` and leaves out whatever git reports. That is every rule
   git itself applies: the root `.gitignore`, a `.gitignore` in any subdirectory
   (`database/.gitignore` ignores `*.sqlite*`, for instance), `.git/info/exclude` and your
   global excludes file (`core.excludesFile`). An ignored directory is left out entirely,
   not shipped as an empty folder. The paths go to git and come back through temporary
   files, NUL-separated and each prefixed with `./`, so git matches every name as written;
   without the prefix, a name starting with `:` would be read as pathspec magic.
   - Files git **tracks** are never excluded this way, even when a pattern matches them:
     `check-ignore` does not report them, and a file committed in spite of `.gitignore` is
     in the repository on purpose.
   - **`public/build/` is the one exception.** It is ignored so that compiled assets never
     cause merge conflicts, but production has no Node, so it is never passed to
     `check-ignore` and always ships. Nothing else the release needs is untracked:
     `vendor/` is installed in staging (step 5) and Filament's assets are published there
     (step 6).
   - The build then prints how many files `.gitignore` excluded beyond the explicit rules,
     grouped by top-level directory — for example
     `.gitignore excluded 3 files beyond the explicit rules: database/ 1, public/ 2.` — so
     anything that should have shipped stands out. Files that later steps throw away
     anyway (anything under `storage/` or `bootstrap/cache/`, `resources/views/dev/`,
     `public/_*` and Filament's published assets) are kept out of that breakdown, and the
     line only gives their total, in brackets: a working tree that has run the test suite
     holds hundreds of them.
   - If `git` is not installed, or the tree is not a git work tree (a source download, or
     a copy without `.git`), it prints one line saying so and stages with the explicit
     rules alone. It does the same if `git check-ignore` fails, or if the repository git
     finds does not track `composer.json`: then the tree is a copy sitting untracked inside
     some other repository, whose ignore rules are not Planvio's and could drop files the
     release needs — all of them, or just `composer.lock` through a `*.lock` of its own.

   **The explicit rules**, which apply with or without git. They are the only thing that
   keeps out files git tracks but a customer has no use for (`tests`, `design`, `.github`,
   `phpunit.xml`, the repository furniture), and where they repeat `.gitignore` they do so
   on purpose, so that a build without git still comes out clean:
   - Top-level directories skipped: `.git`, `.hg`, `.svn`, `.github`, `.idea`, `.vscode`,
     `.zed`, `.nova`, `.fleet`, `.cursor`, `.codex`, `.claude`, `.phpunit.cache`,
     `node_modules`, `vendor`, `build`, `dist`, `tests`, `design`, `scratchpad`, `github`,
     `wiki`, `planvio-wiki` and `planvio.wiki`.
   - Files skipped by name, at any depth: `php`, `composer`, `php.bat`, `composer.bat`,
     `composer.phar`, `auth.json` (Composer's credentials), `phpunit.xml`, `pint.json`,
     `package-lock.json`, `.gitignore`, `.gitattributes`, `.editorconfig`, `.npmrc`,
     `.phpunit.result.cache`, `.phpactor.json`, `_ide_helper.php`, `_ide_helper_models.php`,
     `.phpstorm.meta.php`, `.DS_Store`, `Thumbs.db`, `Homestead.json`, `Homestead.yaml`,
     `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `CHANGELOG.md`, `CLAUDE.md`, `AGENTS.md` and
     `seed-demo.php`.
   - **Every environment file or directory**, at any depth and in any letter case: `.env`,
     and `.env` followed by `.`, `-`, `_` or `~` (`.env.production`, `.env-staging`,
     `.env_backup`, an editor's `.env~`), plus direnv's `.envrc`. Only `.env.example` ships,
     because it is the template the installer starts from.
   - `public/hot`, which an interrupted `npm run dev` leaves behind and which would make
     production load its assets from a dev server.
   - Any `*.local.php` in the root: one-off maintenance scripts, as in `.gitignore`.
   - Local databases, key material, backups and logs: any file ending `.sqlite`, `.sqlite3`
     or `.db` (with or without a `-journal`, `-wal` or `-shm` suffix), `.pem`, `.key`,
     `.p12`, `.pfx`, `.bak` or `.log`.
   - Symbolic links. The one a working tree usually has is `public/storage`, which points
     at an absolute path on your machine; the installer creates the real one on the server.

   After copying it also deletes `resources/views/dev` (the design gallery) and anything in
   `public/` whose name starts with `_` (what that gallery and the localisation reviews
   render).

   Two of the skips are easy to get wrong. `github` is where a working tree may hold a prepared
   copy of the repository itself, so staging it would put the whole source inside the
   release a second time. And the repository furniture — the contributing guide, the code of
   conduct, the changelog — is useful to somebody working *on* Planvio and noise to somebody
   running it: a customer who extracts the ZIP has no pull requests to open.
4. **Storage reset** — deletes the copied `storage/` and rebuilds it as an empty skeleton
   (`app/private`, `app/public`, `framework/{cache/data,sessions,testing,views}`, `logs`),
   then rewrites `storage/.htaccess` and `storage/app/.htaccess` with the deny-and-
   don't-execute guard, and empties `bootstrap/cache` down to its `.gitignore`. Your local
   logs, sessions, uploads and cached manifests never ship.
5. **Production dependencies** — runs
   `composer install --no-dev --optimize-autoloader --classmap-authoritative --no-scripts`
   **inside the staging directory**, so your working tree keeps its dev dependencies and
   stays usable immediately afterwards.
6. **Filament assets** — deletes the staged `public/js/filament`, `public/css/filament`
   and `public/fonts/filament` and publishes them again with `php artisan filament:assets`,
   run inside the staging directory against the vendor that is about to ship. Those files
   are ignored by git, so staging normally leaves them out; a build without git copies them
   from your working tree, where they are from whichever Filament version was last
   installed (or missing, in a fresh clone), and the `--no-scripts` install above never
   refreshes them. A release that paired one Filament version's Blade with
   another's JavaScript would ship a broken admin panel. If Filament's core script or
   stylesheet did not appear, the build fails. Otherwise it rebuilds the empty `storage/`
   and `bootstrap/cache` skeletons, because booting the framework writes package manifests.
7. **Vendor trim** — removes `tests`, `docs`, `examples`, `.github`, `benchmarks` and CI
   config files from each `vendor/<org>/<package>`. It only goes two levels deep on
   purpose: deeper, and it would start deleting directories that packages genuinely ship as
   source.
8. **Safety sweep** — outside `vendor/`, refuses to package if the staging tree contains
   an environment file (or anything inside a directory named like one), an `auth.json`, a
   local database, key or backup by the extensions above, or `.git`, `.hg` or `.svn`
   metadata. These are the copy step's rules for secrets, checked again at the end, so a
   file that reaches staging some other way is still caught. Then it checks that
   `public/index.php`, `public/.htaccess`, `.htaccess`, `artisan`,
   `public/build/manifest.json`, `config/planvio.php`, `config/ai.php` and
   `bootstrap/app.php` are all present.
9. **Archive** — adds every staged file to the ZIP in one pass and closes it once. Closing
   is what actually writes the archive, so it is not flushed along the way: doing that
   rewrites everything already added and made the step quadratic on a tree of about 17,000
   files.
10. **Report** — prints the path, entry count, size and SHA-256. Publish that hash with the
   release.

### Flags

| Flag | Effect |
|---|---|
| `--skip-assets` | Do not run npm. Reuses whatever is in `public/build`. Fails if `manifest.json` is missing |
| `--skip-composer` | Do not run Composer. **The ZIP will have no `vendor/` and no Filament assets, and is not shippable** — use it only to inspect the staging output |
| `--keep-staging` | Leave `build/staging` in place so you can look at exactly what was packaged |

### Finding Composer

`detectComposer()` tries these in order and uses the first one that works:

1. `C:/tools/composer/composer.phar`
2. `<repo>/composer.phar`
3. `/usr/local/bin/composer`
4. `composer`, then `composer.phar`, in each directory on your `PATH`, in order. The
   builder walks `PATH` itself rather than asking the shell, because `command -v` only
   names the first match, and a wrapper there would hide a working Composer further
   along; `composer.phar` is included because Composer-Setup on Windows puts it beside
   the `composer.bat` it adds to `PATH`. This is only searched when none of the first
   three works, and a match only counts if it is a PHP script — an optional `#!` line
   naming PHP, then `<?php` — because a `composer` on `PATH` is often a wrapper PHP cannot
   run, such as a shell script from a version manager.

A candidate that does not exist is passed over silently. One that exists is run as
`<php> <candidate> --version` and accepted only if it exits with 0 and prints a
`Composer version …` line. Otherwise it is listed as rejected, with the exit code or the
first line it printed instead, and the next candidate is tried. A half-downloaded or stray
`composer.phar` in the repository root therefore no longer becomes the build's Composer
just because it exists. The header says which one was chosen and why the others were not:

```
Composer       : /usr/local/bin/composer (Composer version 2.8.12 2025-09-19 13:41:59)
                 rejected /path/to/planvio/composer.phar: `--version` did not print a Composer version; it printed "not composer"
```

The search runs with `--skip-composer` too, so you can see which Composer a full build
would use, but finding none is only an error when Composer is needed. If yours is
somewhere else and not on `PATH`, drop a working `composer.phar` in the repository root —
that is the portable option. It is ignored by git, and the build's exclusion list keeps it
out of the release whether or not it was the one used.

### Before you ship a build

- [ ] `php artisan test` passes, including the `Security` suite
- [ ] `php vendor/bin/pint --test` is clean
- [ ] `config/planvio.php → version` is bumped
- [ ] `db_version` is bumped **if and only if** the release adds migrations
- [ ] The build printed "No .env, key material, database file or VCS metadata found"
- [ ] The `.gitignore excluded …` line names nothing you expected to ship
- [ ] You extracted the ZIP somewhere clean and confirmed `vendor/`,
      `public/build/manifest.json` and `public/js/filament/` are inside it

---

## Limitations

This section describes the repository and its tooling as they stand. What the product
deliberately does not do is in [LIMITATIONS.md](LIMITATIONS.md).

**No static analysis.** There is no PHPStan or Psalm configuration. Pint checks formatting
and the test suite checks behaviour; nothing checks types between the two.

**Tests run one at a time.** `php artisan test --parallel` needs `brianium/paratest`, which
is not a dependency, and the suite is not ready for it anyway: the localisation review
tests write rendered pages to fixed paths under `public/_*.html`, which parallel processes
would overwrite.

**Six tests skip in a fresh checkout, by design.** Five localisation review tests need the
demo workspace and the Arabic locale in the database, and say so when they skip: run
`./php artisan migrate:fresh --seed --force && ./php artisan planvio:demo --force`, then
run them with `DB_DATABASE` pointing at `database/database.sqlite`. The sixth exercises a
file that `finfo_file()` cannot open, and skips on systems where every file opens.

**The suite runs on SQLite only.** CI's MariaDB job migrates fresh with seeds, rolls back
and migrates again, which proves the schema in both directions, but it does not run the
tests against MariaDB. A query that only misbehaves on MySQL or MariaDB has to be caught
in review.

CI itself is `.github/workflows/ci.yml`: the suite on PHP 8.3 and 8.4 (and on 8.4 a second
time, with AI switched off), the MariaDB migrations, Pint, the frontend build with its
check that the right-to-left utilities and the Arabic font are in the stylesheet, and a
check that every English translation key has an Arabic value with its placeholders intact.
