<?php

declare(strict_types=1);

namespace App\Filament\Support;

use BackedEnum;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * A resource over records that are evidence rather than configuration.
 *
 * AI runs, tool runs, audit entries and webhook deliveries are the audit spine: the answer to
 * "what did this system do, and on whose authority". A log an administrator can edit answers
 * nothing, so the only actions this base allows are the ones that read. Every write — create,
 * update, delete, force-delete, restore, replicate, reorder — is refused at the authorization
 * layer, not merely omitted from the UI, so a hand-built URL is refused too.
 *
 * Retention is not an editing surface either: these tables are pruned on a schedule by
 * `planvio:prune`, from `config('planvio.retention')`.
 *
 * @template TModel of Model
 *
 * @extends PlatformResource<TModel>
 */
abstract class LogResource extends PlatformResource
{
    /**
     * The actions a log permits. Anything absent is refused.
     *
     * @var list<string>
     */
    private const READ_ACTIONS = ['viewAny', 'view'];

    /**
     * @param TModel|null $record
     */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $name = match (true) {
            $action instanceof BackedEnum => (string) $action->value,
            $action instanceof UnitEnum => $action->name,
            default => $action,
        };

        if (! in_array($name, self::READ_ACTIONS, true)) {
            return Response::deny(__('Log records are read-only.'));
        }

        return parent::getAuthorizationResponse($action, $record);
    }
}
