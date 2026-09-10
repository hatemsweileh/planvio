<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Tasks;

use App\Actions\Tasks\TaskNumbers;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Deliberately without RefreshDatabase.
 *
 * The guard under test asks whether a transaction is open, and RefreshDatabase opens one
 * around every test it touches — inside that wrapper the question can never be answered
 * honestly. This case therefore runs against a connection nobody has begun a transaction
 * on, which is also why it needs no schema: the guard fires before the first query.
 */
final class TaskNumberTransactionGuardTest extends TestCase
{
    /**
     * Allocating outside a transaction would release the project row lock the instant the
     * counter was written, leaving a window in which a number is spoken for but the task
     * that owns it does not exist yet — and a rollback would burn it for good.
     */
    #[Test]
    public function it_refuses_to_allocate_outside_a_transaction(): void
    {
        $this->expectException(LogicException::class);

        $this->app->make(TaskNumbers::class)->allocate(1);
    }
}
