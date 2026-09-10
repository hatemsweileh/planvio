<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\Ai\RunController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MilestoneController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TimeEntryController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Planvio REST API, v1
|--------------------------------------------------------------------------
|
| Everything here is prefixed `/api/v1` and authenticated with a Sanctum personal access
| token: `Authorization: Bearer <token>`. Tokens are minted on the profile security screen
| and act as the person who created them — there are no service accounts, and no token can
| do anything its owner could not do by hand. Every endpoint below runs the same policy the
| product UI runs, on the same record.
|
| ## Workspace scoping
|
| A token belongs to a person, and a person may sit in several workspaces, so every
| tenant-scoped call names its tenant in the `X-Planvio-Workspace` header — a slug or a
| numeric id. The header is mandatory on those routes and its absence is a 400 rather than a
| guess: defaulting to "the workspace you used last" would let the same script write into
| different tenants on different days. `GET /me` and the two workspace routes sit outside the
| scoped group, because they are how a client discovers what to put in the header.
| {@see \App\Http\Middleware\ApiWorkspace} explains why the header rather than a path segment.
|
| ## How v2 would coexist
|
| The version lives in the path, so it is a routing decision and nothing else. A `v2` group
| would be declared alongside this one in this file, with its own controller namespace
| (`App\Http\Controllers\Api\V2\…`) and its own resources; `v1` keeps pointing at the classes
| it points at today and keeps answering exactly as it does today. Nothing is shared between
| the two except the domain underneath — the Actions, the policies and the models — which is
| the layer that is allowed to change, because it is the layer no client sees.
|
| The rule that makes that work is already in force here: no resource serialises a model.
| Every field in `App\Http\Resources` is written out by hand, so a migration that adds or
| renames a column cannot change a v1 response. What v1 promises is what those classes say,
| not what the schema happens to hold. Adding a key to a response is backwards-compatible and
| does not need a new version; removing one, renaming one, or changing its type does — and
| that is the only thing v2 would be for.
|
| The rate limiter (`planvio-api`) is registered in AppServiceProvider rather than here,
| because a production install caches its routes and a limiter defined in a cached route file
| would never be registered at all.
|
*/

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['auth:sanctum', 'throttle:planvio-api'])
    ->group(function (): void {

        /*
         | Not workspace-scoped: these answer the question "which workspaces may this token
         | name in the header", so requiring the header would be a chicken and egg.
         */
        Route::get('me', MeController::class)->name('me');

        Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show'])->name('workspaces.show');

        /*
         | Everything below is one workspace's data. `api.workspace` resolves the header,
         | refuses a non-member with a 404, and binds the tenant so WorkspaceScope applies —
         | though every controller still names `workspace_id` explicitly and still asks a
         | policy, because the scope is convenience and the policy is the authority (§3).
         */
        Route::middleware('api.workspace')
            /*
             | Ids are big increments and an AI run is addressed by its uuid, so anything else
             | in those segments is not an identifier and is a 404 before a controller or a
             | query sees it. Declared on the group rather than through `Route::pattern()`,
             | which is global and would impose these shapes on the product's own routes —
             | where `{project}` is a slug.
             */
            ->where([
                'project' => '[0-9]+',
                'task' => '[0-9]+',
                'comment' => '[0-9]+',
                'milestone' => '[0-9]+',
                'entry' => '[0-9]+',
                'tag' => '[0-9]+',
                'user' => '[0-9]+',
                'run' => '[0-9a-fA-F-]{36}',
            ])
            ->group(function (): void {

                /* ---------------------------------------------------------- *
                 | Projects
                 * ---------------------------------------------------------- */
                Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
                Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
                Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
                Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
                Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

                /* ---------------------------------------------------------- *
                 | Tasks
                 |
                 | `assign` and `status` are POSTs rather than fields on the PATCH because each
                 | has its own permission and its own consequences — see TaskController.
                 * ---------------------------------------------------------- */
                Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
                Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
                Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
                Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
                Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
                Route::post('tasks/{task}/assign', [TaskController::class, 'assign'])->name('tasks.assign');
                Route::post('tasks/{task}/status', [TaskController::class, 'status'])->name('tasks.status');

                /* ---------------------------------------------------------- *
                 | Comments — on tasks, the one model the domain comments on today
                 * ---------------------------------------------------------- */
                Route::get('tasks/{task}/comments', [CommentController::class, 'index'])->name('tasks.comments.index');
                Route::post('tasks/{task}/comments', [CommentController::class, 'store'])->name('tasks.comments.store');
                Route::get('comments/{comment}', [CommentController::class, 'show'])->name('comments.show');
                Route::patch('comments/{comment}', [CommentController::class, 'update'])->name('comments.update');
                Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');

                /* ---------------------------------------------------------- *
                 | Milestones
                 * ---------------------------------------------------------- */
                Route::get('milestones', [MilestoneController::class, 'index'])->name('milestones.index');
                Route::post('milestones', [MilestoneController::class, 'store'])->name('milestones.store');
                Route::get('milestones/{milestone}', [MilestoneController::class, 'show'])->name('milestones.show');
                Route::patch('milestones/{milestone}', [MilestoneController::class, 'update'])->name('milestones.update');
                Route::delete('milestones/{milestone}', [MilestoneController::class, 'destroy'])->name('milestones.destroy');

                /* ---------------------------------------------------------- *
                 | Time
                 * ---------------------------------------------------------- */
                Route::get('time-entries', [TimeEntryController::class, 'index'])->name('time-entries.index');
                Route::post('time-entries', [TimeEntryController::class, 'store'])->name('time-entries.store');
                Route::get('time-entries/{entry}', [TimeEntryController::class, 'show'])->name('time-entries.show');
                Route::patch('time-entries/{entry}', [TimeEntryController::class, 'update'])->name('time-entries.update');
                Route::delete('time-entries/{entry}', [TimeEntryController::class, 'destroy'])->name('time-entries.destroy');

                /* ---------------------------------------------------------- *
                 | Tags
                 * ---------------------------------------------------------- */
                Route::get('tags', [TagController::class, 'index'])->name('tags.index');
                Route::post('tags', [TagController::class, 'store'])->name('tags.store');
                Route::get('tags/{tag}', [TagController::class, 'show'])->name('tags.show');
                Route::patch('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
                Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');

                /* ---------------------------------------------------------- *
                 | People and history, both read-only — see the controllers for why
                 * ---------------------------------------------------------- */
                Route::get('users', [UserController::class, 'index'])->name('users.index');
                Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');

                Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');

                /*
                 | Search carries a second, much lower budget on top of the token's own.
                 | It is a `LIKE '%term%'` scan across several tables that no index can
                 | serve, so it is the one read here that a loop can turn into a load test
                 | while staying comfortably inside `planvio-api`.
                 */
                Route::get('search', SearchController::class)
                    ->middleware('throttle:planvio-search')
                    ->name('search');

                /* ---------------------------------------------------------- *
                 | AI
                 |
                 | Start a run, poll a run. There is deliberately no endpoint that invokes a
                 | tool: that would be a path around the agent loop's policy and approval gates
                 | (ARCHITECTURE.md §7.1).
                 * ---------------------------------------------------------- */
                /*
                 | Starting a run is throttled hard and polling one is not. `ai.limits`
                 | already caps runs per user per hour and per workspace per day — that is
                 | the provider budget; this is the burst in front of it, so a retry loop
                 | cannot spend an hour's allowance in ten seconds.
                 */
                Route::post('ai/runs', [RunController::class, 'store'])
                    ->middleware('throttle:planvio-ai-run')
                    ->name('ai.runs.store');
                Route::get('ai/runs/{run}', [RunController::class, 'show'])->name('ai.runs.show');
            });
    });
