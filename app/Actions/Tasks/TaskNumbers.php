<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Allocates the per-project task number behind the display key, e.g. `WEB-42`.
 *
 * The counter lives in `projects.task_number_seq` and is protected by
 * `unique(project_id, number)` on `tasks`, which is the last line of defence rather than
 * the mechanism: a collision there surfaces as a failed insert, not as a correct number.
 */
final class TaskNumbers
{
    /**
     * Take the next number for a project.
     *
     * Why the locked read, spelled out:
     *
     * Two people creating a task in the same project at the same moment run two
     * transactions that both want `seq + 1`. A plain `SELECT task_number_seq` would let
     * both read the same value — under MySQL's default REPEATABLE READ each sees the
     * snapshot taken when its transaction began — and both would then write the same
     * number, colliding on `unique(project_id, number)` (or, on an engine with weaker
     * isolation and no such index, silently producing two tasks called `WEB-42`).
     *
     * `lockForUpdate()` turns the read into `SELECT ... FOR UPDATE`, which takes an
     * exclusive row lock on the project. The second transaction blocks on that lock at its
     * own SELECT and is only released by the first transaction's COMMIT — at which point it
     * re-reads the *already incremented* value rather than the stale snapshot. That is what
     * makes the read-modify-write indivisible: the lock spans the whole transaction, not
     * just the statement, so no one can slip between the read and the write.
     *
     * This is also why the allocation must happen inside the same transaction as the insert
     * of the task. Allocating in its own short transaction would release the lock at once
     * and leave a gap in which a rolled-back insert burns a number — harmless — but, worse,
     * it would let the caller believe the number is reserved when the row it belongs to was
     * never written.
     *
     * SQLite has no row locks and ignores `FOR UPDATE`, but it serialises writers behind a
     * single database-level write lock, so the same guarantee holds there without the hint.
     */
    public function allocate(Project|int $project): int
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'Task numbers must be allocated inside the transaction that inserts the task, '
                .'so that the row lock on the project is held until the task exists.',
            );
        }

        $projectId = $project instanceof Project ? (int) $project->getKey() : $project;

        $current = DB::table('projects')
            ->where('id', $projectId)
            ->lockForUpdate()
            ->value('task_number_seq');

        $next = (int) $current + 1;

        DB::table('projects')
            ->where('id', $projectId)
            ->update(['task_number_seq' => $next]);

        return $next;
    }
}
