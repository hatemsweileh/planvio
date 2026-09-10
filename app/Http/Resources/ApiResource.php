<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Middleware\ApiWorkspace;
use App\Support\CurrentWorkspace;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * The base every Planvio API resource extends.
 *
 * ## Allow-lists, never `toArray()` on the model
 *
 * Not one resource in this namespace serialises a model. Every field is named, and a column
 * added by a future migration is invisible to the API until somebody adds it here on purpose.
 * The alternative — `$this->resource->toArray()` with a `$hidden` list to subtract from — is
 * a deny-list, and a deny-list is one migration away from publishing a column nobody meant to
 * publish. Two of the columns that would be published that way are `users.two_factor_secret`
 * and `users.password`.
 *
 * ## Nothing here may query
 *
 * `Model::preventLazyLoading()` is on outside production, so a resource that reads an
 * unloaded relation throws. Relations are therefore reached through `whenLoaded()` only, and
 * the controller is responsible for eager-loading what it renders. In production the same
 * discipline is what keeps a page of fifty tasks at a fixed query count.
 *
 * The three helpers below exist because every resource needs the same three conversions and
 * because the difference between an instant and a date matters to a client: `timestamp`
 * columns go out as ISO-8601 with an offset, `date` columns go out as bare `Y-m-d`, and an
 * absent value is `null` rather than an empty string.
 */
abstract class ApiResource extends JsonResource
{
    /**
     * An instant, as ISO-8601 with an offset.
     */
    protected static function iso(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return Carbon::instance($value)->toIso8601String();
    }

    /**
     * A calendar date, with no time and no zone — a due date is the same day everywhere.
     */
    protected static function date(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return Carbon::instance($value)->toDateString();
    }

    /**
     * A nullable foreign key, as an integer rather than whatever the driver handed back.
     */
    protected static function id(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * A backed enum's stored value. Enum *labels* are deliberately never sent: they are
     * translated, so a client that compared against one would break when the caller's locale
     * changed.
     */
    protected static function enum(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? (string) $value->value : null;
    }

    /**
     * The product URL for a record — where a person would go to look at the thing the API
     * just described. Integrations post these into chat and issue trackers, so they are
     * absolute.
     *
     * Built from the workspace bound by {@see ApiWorkspace}, not from
     * the record's own `workspace` relation: reading that relation would be a lazy load on
     * every row of every list, and the bound tenant is the same workspace by construction —
     * a record from another one cannot reach a resource, because the policy refused it first.
     */
    protected static function appUrl(string $suffix): ?string
    {
        $slug = Container::getInstance()->make(CurrentWorkspace::class)->get()?->slug;

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return url('/w/'.$slug.$suffix);
    }
}
