<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\Comments\CommentCreated;
use App\Events\Members\InvitationAccepted;
use App\Events\Members\MemberInvited;
use App\Events\Members\MemberJoined;
use App\Events\Members\ProjectMemberAdded;
use App\Events\Milestones\MilestoneCompleted;
use App\Events\Projects\ProjectCreated;
use App\Events\Projects\ProjectUpdated;
use App\Events\Tasks\TaskAssigned;
use App\Events\Tasks\TaskCompleted;
use App\Events\Tasks\TaskCreated;
use App\Events\Tasks\TaskStatusChanged;
use App\Events\Tasks\TaskUpdated;
use App\Listeners\Members\NotifyProjectMember;
use App\Listeners\Members\RaiseMemberJoined;
use App\Listeners\Milestones\NotifyMilestoneCompleted;
use App\Listeners\Tasks\NotifyTaskAssignee;
use App\Listeners\Tasks\NotifyTaskCompleted;
use App\Listeners\Tasks\RaiseTaskCompleted;
use App\Listeners\Webhooks\DispatchWebhooks;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as FrameworkEventServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The whole event wiring of the product, written out.
 *
 * Laravel 13 discovers listeners in `app/Listeners` automatically, and that is switched off
 * here on purpose. Discovery reads the type hint on `handle()`, which cannot express
 * {@see DispatchWebhooks} — one listener bound to eleven unrelated events — and it hides the
 * ordering below, which matters: on a status change the webhook for `task.status_changed`
 * has to be queued before {@see RaiseTaskCompleted} raises `TaskCompleted`, or a receiver
 * would be told a task was completed before being told it moved.
 *
 * Discovery is disabled in `register()` rather than in `bootstrap/app.php` so this file is
 * self-contained: the class that adds the listeners is also the class that stops them being
 * added twice. Registering here and discovering as well would fire every listener twice, and
 * a duplicated notification is the kind of bug that is reported as "spam" rather than as a
 * fault.
 *
 * This provider deliberately does not extend the framework's, which would register
 * `SendEmailVerificationNotification` a second time alongside the copy the framework's own
 * event provider adds.
 *
 * ### What is not here
 *
 * Some notifications are raised by the Action that owns the change rather than by a listener:
 * `CreateComment` notifies the people it mentioned, `ChangeTaskStatus` notifies watchers, and
 * `InviteMember` sends the invitation mail. Those actions know things a listener would have
 * to re-derive — who was newly mentioned as against previously mentioned, which watchers
 * existed before the change — so the listeners below deliberately do not re-notify for the
 * same events.
 */
final class EventServiceProvider extends ServiceProvider
{
    /**
     * Event class => listeners, in the order they run.
     *
     * @var array<class-string, list<class-string>>
     */
    private const LISTEN = [
        TaskCreated::class => [DispatchWebhooks::class],
        TaskUpdated::class => [DispatchWebhooks::class],
        TaskAssigned::class => [NotifyTaskAssignee::class, DispatchWebhooks::class],
        TaskStatusChanged::class => [DispatchWebhooks::class, RaiseTaskCompleted::class],
        TaskCompleted::class => [NotifyTaskCompleted::class, DispatchWebhooks::class],

        CommentCreated::class => [DispatchWebhooks::class],

        ProjectCreated::class => [DispatchWebhooks::class],
        ProjectUpdated::class => [DispatchWebhooks::class],

        MilestoneCompleted::class => [NotifyMilestoneCompleted::class, DispatchWebhooks::class],

        MemberInvited::class => [DispatchWebhooks::class],
        InvitationAccepted::class => [RaiseMemberJoined::class],
        MemberJoined::class => [DispatchWebhooks::class],
        ProjectMemberAdded::class => [NotifyProjectMember::class],
    ];

    public function register(): void
    {
        // Runs before the framework's event provider is registered — that happens in a
        // `booting` callback, after every provider in bootstrap/providers.php has been
        // registered — so the flag is already set by the time discovery would have run.
        FrameworkEventServiceProvider::disableEventDiscovery();
    }

    public function boot(): void
    {
        foreach (self::LISTEN as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }
}
