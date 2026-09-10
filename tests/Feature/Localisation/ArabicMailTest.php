<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Enums\WorkspaceRole;
use App\Models\Locale;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Tasks\TaskAssigned;
use App\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Notification mail in a right-to-left language.
 *
 * Mail is the one surface that leaves the application: there is no request, no middleware
 * and nothing shared with the view, so everything the web layouts get from `SetLocale` has
 * to be resolved by the notification itself. Two separate things were wrong and each made
 * the other invisible.
 *
 * The message never reached the reader's language at all. Laravel asks a notifiable for a
 * preferred locale and asks nothing when the contract is absent, so a queued notification
 * rendered in whatever the worker had — `config('app.locale')` — and an Arabic account got
 * English mail linking to an Arabic product.
 *
 * And the shell was written for one direction. `align="left"` has no logical spelling, Word
 * ignores a direction inherited into a nested table, and the fact table's gutters were
 * `padding-left` and `padding-right`, so even once the words were Arabic the layout was not.
 */
final class ArabicMailTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private Task $task;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Locale::SHIPPED as $shipped) {
            Locale::query()->create($shipped + ['is_enabled' => true]);
        }

        $this->workspace = $this->makeWorkspace();
        $this->actor = $this->makeMember($this->workspace, WorkspaceRole::Admin, ['name' => 'Dana Whitfield']);
        $this->project = $this->makeProject($this->workspace);
        $this->task = $this->makeTask($this->project, ['title' => 'Add a customer story template']);
    }

    #[Test]
    public function a_notification_renders_in_the_readers_language(): void
    {
        $this->assertSame('en', config('app.locale'));

        $reader = $this->makeMember($this->workspace, WorkspaceRole::Member, ['locale' => 'ar']);

        $html = $this->render($reader);

        $this->assertStringContainsString('lang="ar"', $html);
        $this->assertStringContainsString(
            __(':actor assigned you a task', ['actor' => 'Dana Whitfield'], 'ar'),
            $html,
        );
    }

    #[Test]
    public function the_shell_is_turned_around_for_it(): void
    {
        $reader = $this->makeMember($this->workspace, WorkspaceRole::Member, ['locale' => 'ar']);

        $html = $this->render($reader);

        // On <html>, on <body> and on every nested table: Word renders through a layout
        // engine that does not inherit direction into a nested table.
        $this->assertGreaterThanOrEqual(5, substr_count($html, 'dir="rtl"'));

        // The mark and wordmark sit on the reading edge, not on the left.
        $this->assertStringContainsString('align="right"', $html);
        $this->assertStringNotContainsString('align="left"', $html);

        // The gutters of the fact table follow the reading direction.
        $this->assertStringContainsString('padding-right:16px', $html);
        $this->assertStringNotContainsString('letter-spacing:-0.02em', $html);

        // A face that actually carries the script, since no web font reaches a mail client.
        $this->assertStringContainsString('Noto Sans Arabic', $html);
    }

    #[Test]
    public function the_link_a_reader_copies_is_isolated_from_the_prose(): void
    {
        $reader = $this->makeMember($this->workspace, WorkspaceRole::Member, ['locale' => 'ar']);

        $html = $this->render($reader);

        // Unmarked, the bidi algorithm resolves the trailing character of a URL to the
        // paragraph and prints it at the other end of the line.
        $this->assertMatchesRegularExpression('/<a href="[^"]+" dir="ltr"/', $html);
    }

    #[Test]
    public function english_mail_is_unchanged(): void
    {
        $reader = $this->makeMember($this->workspace, WorkspaceRole::Member, ['locale' => 'en']);

        $html = $this->render($reader);

        $this->assertStringContainsString('lang="en"', $html);
        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertStringContainsString('align="left"', $html);
        $this->assertStringNotContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('Dana Whitfield assigned you a task', $html);
    }

    #[Test]
    public function a_language_this_installation_no_longer_offers_is_not_honoured(): void
    {
        /*
         * `users.locale` is an ordinary column, and a code left behind by a language that
         * was switched off is not permission to render in it — the same rule SetLocale
         * applies to a page.
         */
        Locale::query()->where('code', 'ar')->update(['is_enabled' => false]);

        $reader = $this->makeMember($this->workspace, WorkspaceRole::Member, ['locale' => 'ar']);

        $this->assertNull($reader->preferredLocale());
        $this->assertStringContainsString('lang="en"', $this->render($reader));
    }

    #[Test]
    public function the_send_does_not_leave_the_process_in_the_readers_language(): void
    {
        $reader = $this->makeMember($this->workspace, WorkspaceRole::Member, ['locale' => 'ar']);

        $this->render($reader);

        $this->assertSame('en', app()->getLocale());
    }

    /**
     * The message a mail client would receive, through the real send path — which is where
     * the locale is decided, and the only place `preferredLocale()` is consulted.
     */
    private function render(User $reader): string
    {
        /** @var ArrayTransport $transport */
        $transport = Mail::getSymfonyTransport();
        $transport->flush();

        // Channels are the reader's to choose; this test is about what the mail says, not
        // about whether they asked for it.
        $reader->notify(
            (new TaskAssigned($this->task, $this->actor))->onChannels([NotificationDispatcher::CHANNEL_MAIL]),
        );

        $message = $transport->messages()->last();

        $this->assertNotNull($message, 'No mail was sent.');

        return (string) $message->getOriginalMessage()->getHtmlBody();
    }
}
