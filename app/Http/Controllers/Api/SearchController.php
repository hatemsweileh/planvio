<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\ApiResponse;
use App\Services\SearchService;
use App\Services\SearchType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/search?q=…` — the command palette, as an endpoint.
 *
 * It is the same {@see SearchService} the product uses, which matters more than it sounds:
 * the service resolves the caller's workspace role once and gates every one of the seven
 * result groups through the same `Project::visibleTo()` subquery, so a guest searching the
 * API gets exactly the results a guest searching the product would get. Writing a second,
 * API-shaped search would mean writing those seven visibility rules a second time.
 *
 * The response is grouped by type rather than flattened, because the groups mean different
 * things — a task and a comment that both match "invoice" are not comparable, and a single
 * ranked list would have to pretend they are.
 */
final class SearchController extends ApiController
{
    public function __invoke(Request $request, SearchService $search): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $this->actor($request);

        $validated = $request->validate([
            'q' => ['required', 'string', 'max:128'],
            'types' => ['nullable', 'array', 'max:7'],
            'types.*' => ['string', 'max:32'],
            // Per group, not in total. The service caps it again at its own ceiling.
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'include_archived' => ['nullable', 'boolean'],
        ]);

        /** @var list<string> $types */
        $types = is_array($validated['types'] ?? null) ? array_values($validated['types']) : [];

        $results = $search->search(
            term: (string) $validated['q'],
            user: $user,
            workspace: $workspace,
            types: $types === [] ? null : $types,
            limit: isset($validated['limit']) ? (int) $validated['limit'] : null,
            includeArchived: $request->boolean('include_archived'),
        );

        return ApiResponse::raw($results->toArray(), meta: [
            'types' => array_map(
                static fn (SearchType $type): string => $type->value,
                SearchType::cases(),
            ),
        ]);
    }
}
