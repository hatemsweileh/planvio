<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Wiki\UpdateWikiPage;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Enums\WikiVisibility;
use App\Models\WikiPage;

/**
 * Edit an existing wiki page.
 *
 * ## The whole-document trap
 *
 * {@see UpdateWikiPage} writes the title and the body it is given, both of them, every time —
 * it is a save, not a patch. So a tool that passed `null` for a body the model did not mention
 * would erase the page. That is the single most destructive thing anything in this file could
 * do by accident, and it would not look like a failure: the call succeeds, the row saves, and
 * a page somebody spent an afternoon on is empty.
 *
 * The defence is explicit and deliberately boring: an argument that is *absent* is filled in
 * from the page as it stands, so only what the model actually sent can change. Clearing the
 * body remains possible — pass an empty string — because sometimes that is the request, but it
 * has to be said rather than implied by silence.
 *
 * ## The URL
 *
 * The slug does not follow the title unless `rename_url` says so. Page URLs get bookmarked and
 * linked from other pages, so fixing a typo in a heading must not quietly break every
 * reference to it.
 */
final class UpdateDocumentTool implements AiTool
{
    use MutatesThroughActions;

    public function __construct(private readonly UpdateWikiPage $updateWikiPage) {}

    public function name(): string
    {
        return 'update_document';
    }

    public function group(): string
    {
        return 'wiki';
    }

    public function description(): string
    {
        return 'Edit a wiki page. Fields you do not send keep their current value — omitting '
            .'content leaves the page body alone; send an empty string to genuinely clear it. '
            .'The page URL does not change unless rename_url is true.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['wiki_page_id'],
            'properties' => [
                'wiki_page_id' => ['type' => 'integer', 'minimum' => 1],
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'content' => [
                    'type' => 'string',
                    'maxLength' => 100000,
                    'description' => 'The full new body. Omit to leave the body untouched; "" clears it.',
                ],
                'visibility' => ['type' => 'string', 'enum' => ['project', 'workspace', 'private']],
                'excerpt' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'rename_url' => [
                    'type' => 'boolean',
                    'description' => 'Regenerate the page slug from the new title. Breaks existing links.',
                ],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Low;
    }

    public function permission(): ?Permission
    {
        return Permission::WikiManage;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->edit($in, $ctx));
    }

    private function edit(Arguments $in, AgentContext $ctx): ToolResult
    {
        $id = $in->int('wiki_page_id');
        $page = $this->resolveWikiPage($id, $ctx);

        if (! $page instanceof WikiPage) {
            return $this->notFound(__('wiki page'), $id);
        }

        $ctx->assertInWorkspace($page);

        if ($ctx->cannot(Permission::WikiManage, $page)) {
            return $this->denied($ctx, __('edit the wiki page ":title"', ['title' => $page->title]));
        }

        // Absent means "leave it": the action saves whatever it is handed, so the current
        // values stand in for everything the model did not mention.
        $title = $in->text('title') ?? (string) $page->title;
        $content = $in->has('content') ? $in->string('content') : ($page->content === null ? null : (string) $page->content);

        $before = [
            'title' => $page->title,
            'visibility' => $page->visibility->value,
            'slug' => $page->slug,
            'content_length' => mb_strlen((string) $page->content),
        ];

        $page = ($this->updateWikiPage)(
            page: $page,
            editor: $ctx->user,
            title: $title,
            content: $content,
            visibility: $in->enum('visibility', WikiVisibility::class),
            excerpt: $in->text('excerpt'),
            reslug: $in->bool('rename_url'),
        );

        $after = [
            'title' => $page->title,
            'visibility' => $page->visibility->value,
            'slug' => $page->slug,
            'content_length' => mb_strlen((string) $page->content),
        ];

        $changed = array_keys(array_diff_assoc($after, $before));

        return ToolResult::ok(
            $changed === []
                ? __('The wiki page ":title" already read that way, so nothing changed.', [
                    'title' => self::clip($page->title, 100),
                ])
                : __('Updated the wiki page ":title" (:fields). The body is now :length characters.', [
                    'title' => self::clip($page->title, 100),
                    'fields' => implode(', ', $changed),
                    'length' => $after['content_length'],
                ]),
            [
                'wiki_page_id' => (int) $page->getKey(),
                'title' => $page->title,
                'slug' => $page->slug,
                'visibility' => $page->visibility->value,
                'changed_fields' => $changed,
                'content_length_before' => $before['content_length'],
                'content_length_after' => $after['content_length'],
                'edited_by' => $ctx->user->name,
            ],
            $page,
        );
    }
}
