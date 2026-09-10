<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Tags;

use App\Actions\Tags\AttachTag;
use App\Actions\Tags\CreateTag;
use App\Actions\Tags\DeleteTag;
use App\Actions\Tags\DetachTag;
use App\Actions\Tags\SyncTags;
use App\Actions\Tags\TagChanges;
use App\Actions\Tags\TagNotInWorkspace;
use App\Actions\Tags\TagSlugTaken;
use App\Actions\Tags\UpdateTag;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\CreateTaskData;
use App\Enums\StatusCategory;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TagActionsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $actor;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace();
        $this->actor = $this->makeMember($this->workspace);
        $this->project = $this->makeProject($this->workspace);

        TaskStatus::factory()->asDefault()->create([
            'project_id' => $this->project->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'category' => StatusCategory::Todo,
        ]);

        $this->task = $this->app->make(CreateTask::class)(new CreateTaskData(
            project: $this->project,
            actor: $this->actor,
            title: 'A task to label',
        ));
    }

    #[Test]
    public function it_creates_a_tag_with_a_slug_from_the_name(): void
    {
        $tag = $this->tag('Needs Design');

        $this->assertSame('needs-design', $tag->slug);
        $this->assertSame((int) $this->workspace->getKey(), (int) $tag->workspace_id);
    }

    /**
     * Several people type into the same autocomplete at once, so "create or give me the one
     * that already exists" is the useful contract — and it makes the action safe to retry.
     */
    #[Test]
    public function creating_the_same_tag_twice_returns_the_first_one(): void
    {
        $first = $this->tag('Needs Design');
        $second = $this->tag('needs design');

        $this->assertSame((int) $first->getKey(), (int) $second->getKey());
        $this->assertSame(1, Tag::query()->count());
    }

    /**
     * A name in a script that transliterates to nothing would slug to the empty string, and
     * then every such tag in a workspace would collide with the first one created.
     */
    #[Test]
    public function a_name_that_slugs_to_nothing_still_gets_a_unique_slug(): void
    {
        $first = $this->tag('!!!');
        $second = $this->tag('???');

        $this->assertNotSame($first->slug, $second->slug);
        $this->assertNotSame('', $first->slug);
    }

    #[Test]
    public function renaming_a_tag_moves_its_slug(): void
    {
        $tag = $this->tag('Needs Design');

        $this->app->make(UpdateTag::class)($tag, TagChanges::make()->name('Needs Review'), $this->actor);

        $this->assertSame('needs-review', $tag->fresh()->slug);
    }

    #[Test]
    public function it_refuses_a_rename_onto_a_slug_another_tag_holds(): void
    {
        $this->tag('Needs Design');
        $other = $this->tag('Needs Review');

        $this->expectException(TagSlugTaken::class);

        $this->app->make(UpdateTag::class)($other, TagChanges::make()->name('Needs Design'), $this->actor);
    }

    #[Test]
    public function an_edit_that_changes_nothing_records_nothing(): void
    {
        $tag = $this->tag('Needs Design');

        $this->app->make(UpdateTag::class)($tag, TagChanges::make()->name('Needs Design'), $this->actor);

        $this->assertSame(0, Activity::query()->where('event', 'updated')->count());
    }

    #[Test]
    public function attaching_the_same_tag_twice_leaves_one_row(): void
    {
        $tag = $this->tag('Needs Design');
        $attach = $this->app->make(AttachTag::class);

        $attach($this->task, $tag, $this->actor);
        $attach($this->task, $tag, $this->actor);

        $this->assertSame(1, $this->task->tags()->count());
        $this->assertSame(1, Activity::query()->where('event', 'tag_added')->count());
    }

    /**
     * `taggables` has no workspace column of its own, so this boundary exists only where the
     * two rows are joined — here.
     */
    #[Test]
    public function it_refuses_a_tag_from_another_workspace(): void
    {
        $otherWorkspace = $this->makeWorkspace();
        $foreign = $this->app->make(CreateTag::class)($otherWorkspace, 'Elsewhere', $this->makeMember($otherWorkspace));

        $this->expectException(TagNotInWorkspace::class);

        $this->app->make(AttachTag::class)($this->task, $foreign, $this->actor);
    }

    #[Test]
    public function detaching_a_tag_that_was_not_there_records_nothing(): void
    {
        $tag = $this->tag('Needs Design');

        $this->app->make(DetachTag::class)($this->task, $tag, $this->actor);

        $this->assertSame(0, Activity::query()->where('event', 'tag_removed')->count());
    }

    #[Test]
    public function syncing_replaces_the_whole_set(): void
    {
        $design = $this->tag('Needs Design');
        $review = $this->tag('Needs Review');
        $urgent = $this->tag('Urgent');

        $this->app->make(AttachTag::class)($this->task, $design, $this->actor);

        $this->app->make(SyncTags::class)(
            $this->task,
            [(int) $review->getKey(), (int) $urgent->getKey()],
            $this->actor,
        );

        $this->assertSame(
            [$review->slug, $urgent->slug],
            $this->task->tags()->orderBy('slug')->pluck('slug')->sort()->values()->all(),
        );
    }

    #[Test]
    public function syncing_the_set_that_is_already_there_records_nothing(): void
    {
        $tag = $this->tag('Needs Design');

        $this->app->make(SyncTags::class)($this->task, [(int) $tag->getKey()], $this->actor);
        $this->app->make(SyncTags::class)($this->task, [(int) $tag->getKey()], $this->actor);

        $this->assertSame(1, Activity::query()->where('event', 'tags_synced')->count());
    }

    /**
     * One foreign id must abort the whole sync rather than attach the good ones and fail
     * halfway, which would leave the record in a state nobody asked for.
     */
    #[Test]
    public function a_sync_containing_a_foreign_tag_changes_nothing_at_all(): void
    {
        $mine = $this->tag('Needs Design');

        $otherWorkspace = $this->makeWorkspace();
        $foreign = $this->app->make(CreateTag::class)($otherWorkspace, 'Elsewhere', $this->makeMember($otherWorkspace));

        try {
            $this->app->make(SyncTags::class)(
                $this->task,
                [(int) $mine->getKey(), (int) $foreign->getKey()],
                $this->actor,
            );
            $this->fail('A tag from another workspace was accepted.');
        } catch (TagNotInWorkspace) {
            // expected
        }

        $this->assertSame(0, $this->task->tags()->count());
    }

    #[Test]
    public function deleting_a_tag_takes_it_off_everything(): void
    {
        $tag = $this->tag('Needs Design');

        $this->app->make(AttachTag::class)($this->task, $tag, $this->actor);
        $this->app->make(DeleteTag::class)($tag, $this->actor);

        $this->assertDatabaseMissing('tags', ['id' => $tag->getKey()]);
        $this->assertSame(0, $this->task->tags()->count());
        $this->assertDatabaseHas('activities', ['event' => 'deleted', 'subject_type' => $tag->getMorphClass()]);
    }

    private function tag(string $name): Tag
    {
        return $this->app->make(CreateTag::class)($this->workspace, $name, $this->actor);
    }
}
