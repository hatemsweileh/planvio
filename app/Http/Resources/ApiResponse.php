<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * The one shape every successful API response has.
 *
 * ```
 * { "data": … }                       a record, or a list of records
 * { "data": [ … ], "meta": { … } }    a page of records, with the cursor facts
 * ```
 *
 * `data` is always present and is always the thing that was asked for. `meta` carries
 * pagination and nothing else that changes per endpoint — a client that learns the envelope
 * once can read every route in the API.
 *
 * Resources are resolved through here rather than returned directly so the wrapper cannot
 * drift: Laravel's own `JsonResource` wrapping is configurable globally and per class, and an
 * API whose envelope depends on which class happened to render it is an API with two
 * contracts. See {@see ApiError} for the failure half of the same envelope.
 */
final class ApiResponse
{
    /**
     * One record.
     *
     * @param array<string, mixed> $meta
     */
    public static function item(JsonResource $resource, int $status = 200, array $meta = []): JsonResponse
    {
        $payload = ['data' => $resource->resolve(self::request())];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * A list that is not paginated — a fixed, bounded set such as the workspaces a token can
     * reach. `meta.total` is still sent so a client never has to count the array itself.
     *
     * @param iterable<int, mixed> $items
     * @param class-string<JsonResource> $resource
     * @param array<string, mixed> $meta
     */
    public static function collection(iterable $items, string $resource, array $meta = []): JsonResponse
    {
        $data = self::map($items, $resource);

        return response()->json([
            'data' => $data,
            'meta' => ['total' => count($data)] + $meta,
        ]);
    }

    /**
     * A page of records.
     *
     * The four numbers are everything a caller needs to walk the collection and nothing that
     * would tie them to Laravel's paginator: no generated URLs, no `path`, no `from`/`to`.
     * URLs built by the server go stale the moment a client is behind a proxy that rewrites
     * the host, and a page number plus a page size never does.
     *
     * @param LengthAwarePaginator<int, mixed> $paginator
     * @param class-string<JsonResource> $resource
     * @param array<string, mixed> $meta
     */
    public static function paginated(LengthAwarePaginator $paginator, string $resource, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => self::map($paginator->items(), $resource),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ] + $meta,
        ]);
    }

    /**
     * A payload that is not a record — the search index, an acknowledgement.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    public static function raw(array $data, int $status = 200, array $meta = []): JsonResponse
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * A deletion. No body: there is nothing left to describe, and inventing one would invite
     * a client to depend on it.
     */
    public static function deleted(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * @param iterable<int, mixed> $items
     * @param class-string<JsonResource> $resource
     * @return list<array<string, mixed>>
     */
    private static function map(iterable $items, string $resource): array
    {
        $request = self::request();

        return Collection::make($items)
            ->map(static fn (mixed $item): array => (new $resource($item))->resolve($request))
            ->values()
            ->all();
    }

    private static function request(): Request
    {
        return request();
    }
}
