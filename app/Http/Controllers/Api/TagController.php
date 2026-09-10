<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tags\CreateTag;
use App\Actions\Tags\DeleteTag;
use App\Actions\Tags\UpdateTag;
use App\Http\Requests\Api\StoreTagRequest;
use App\Http\Requests\Api\UpdateTagRequest;
use App\Http\Resources\ApiResponse;
use App\Http\Resources\TagResource;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A workspace's shared vocabulary.
 *
 * Creation is idempotent by slug: asking for a tag that already exists hands back the
 * existing one rather than failing on the unique index, which is
 * {@see CreateTag}'s contract and what makes an import script safe to re-run. The status code
 * says which happened — `201` for a tag that was created, `200` for one that was already
 * there — so a caller does not have to guess or check first.
 */
final class TagController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);

        $this->authorize('viewAny', [Tag::class, $workspace]);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:128'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Tag::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspace->getKey())
            ->when(
                isset($filters['q']) && trim((string) $filters['q']) !== '',
                fn (Builder $q): Builder => $q->whereRaw(self::likeSql('tags.name'), [self::likePattern((string) $filters['q'])]),
            )
            ->orderBy('name')
            ->orderBy('id');

        return ApiResponse::paginated($this->paginate($request, $query), TagResource::class);
    }

    public function show(Request $request, string $tag): JsonResponse
    {
        $record = $this->findInWorkspace($request, Tag::class, $tag);

        $this->authorize('view', $record);

        return ApiResponse::item(new TagResource($record));
    }

    public function store(StoreTagRequest $request, CreateTag $create): JsonResponse
    {
        $workspace = $this->workspace($request);

        $this->authorize('create', [Tag::class, $workspace]);

        $color = $request->color();

        $tag = $color === null
            ? $create($workspace, $request->name(), $this->actor($request), description: $request->description())
            : $create($workspace, $request->name(), $this->actor($request), $color, $request->description());

        // `wasRecentlyCreated` is how the action's "return the existing one" contract becomes
        // visible to a caller without a second round trip.
        return ApiResponse::item(new TagResource($tag), $tag->wasRecentlyCreated ? 201 : 200);
    }

    public function update(UpdateTagRequest $request, string $tag, UpdateTag $update): JsonResponse
    {
        $record = $this->findInWorkspace($request, Tag::class, $tag);

        $this->authorize('update', $record);

        $updated = $update($record, $request->toChanges(), $this->actor($request));

        return ApiResponse::item(new TagResource($updated));
    }

    public function destroy(Request $request, string $tag, DeleteTag $delete): JsonResponse
    {
        $record = $this->findInWorkspace($request, Tag::class, $tag);

        $this->authorize('delete', $record);

        $delete($record, $this->actor($request));

        return ApiResponse::deleted();
    }
}
