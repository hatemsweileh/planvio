<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Export\CsvStream;
use App\Services\Export\ExportFactory;
use App\Services\Export\ExportFilters;
use App\Services\Export\ExportType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands back a CSV of whatever the export screen is showing.
 *
 * A plain GET rather than a Livewire action, for one reason: Livewire delivers a download by
 * encoding the whole file into its JSON response, which means holding it in memory twice.
 * Everything here is produced while it is being sent (see {@see CsvStream}), so the size of
 * the export is bounded by the database, not by `memory_limit`.
 *
 * The filters arrive in the query string and are re-read from scratch —
 * {@see ExportFilters::fromArray()} keeps only what it recognises. Nothing about the row set
 * is taken on trust from the caller: the workspace comes from the route binding and the
 * permission check, and every export narrows itself to the projects the acting user may see.
 */
final class ExportController extends Controller
{
    public function __construct(private readonly ExportFactory $factory) {}

    public function __invoke(Request $request, Workspace $workspace, string $type): StreamedResponse
    {
        $exportType = ExportType::tryFrom($type);

        abort_if($exportType === null, 404);

        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        // Bulk extraction is a reporting act: it is the one screen that can put a whole
        // workspace into a spreadsheet, so it asks for `reports.view` — which guests do not
        // hold — on top of the per-project narrowing every export applies to its own rows.
        Gate::forUser($user)->authorize('view', $workspace);
        Gate::forUser($user)->authorize(Permission::ReportsView->value, $workspace);

        $filters = ExportFilters::fromArray($request->query());
        $export = $this->factory->make($exportType, $workspace, $user, $filters);

        return CsvStream::download(
            CsvStream::filename((string) $workspace->slug, $export->subject()),
            $export->headers(),
            $export->rows(),
        );
    }
}
