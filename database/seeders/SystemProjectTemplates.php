<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Projects\CreateProjectFromTemplate;
use App\Enums\MilestoneStatus;
use App\Enums\Priority;
use App\Enums\ProjectType;
use App\Enums\StatusCategory;
use App\Enums\ViewType;

/**
 * The project blueprints Planvio ships with (ARCHITECTURE.md §5.6).
 *
 * Each row is a `project_templates` record whose `definition` is read by
 * {@see CreateProjectFromTemplate}. That action documents the shape;
 * this class is the content. Day offsets are counted from the project's start date, so the
 * same blueprint produces a sensible schedule whenever somebody uses it.
 *
 * The templates are written to be recognisable to the person who does the work — a marketer
 * opening "Marketing Campaign" should see the plan they would have written themselves, not a
 * three-column skeleton. That is why the task lists are long and specific: a template nobody
 * trusts is deleted on first use, and then the feature has cost more than it gave.
 */
final class SystemProjectTemplates
{
    private function __construct() {}

    /**
     * Every shipped template, in the order they are offered.
     *
     * @return list<array{
     *     name: string,
     *     slug: string,
     *     description: string,
     *     icon: string,
     *     color: string,
     *     type: ProjectType,
     *     definition: array<string, mixed>
     * }>
     */
    public static function all(): array
    {
        return [
            self::general(),
            self::marketingCampaign(),
            self::productLaunch(),
            self::eventPlanning(),
            self::websiteProject(),
            self::softwareProject(),
            self::hiringProject(),
        ];
    }

    /* ------------------------------------------------------------------ *
     * General
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private static function general(): array
    {
        return [
            'name' => 'General',
            'slug' => 'general',
            'description' => 'A plain project with a simple board, three checkpoints and the housekeeping every piece of work needs.',
            'icon' => '📋',
            'color' => '#3F66B0',
            'type' => ProjectType::General,
            'definition' => [
                'statuses' => [
                    self::status('Backlog', StatusCategory::Backlog),
                    self::status('To do', StatusCategory::Todo, isDefault: true),
                    self::status('In progress', StatusCategory::InProgress),
                    self::status('Review', StatusCategory::Review),
                    self::status('Blocked', StatusCategory::Blocked),
                    self::status('Done', StatusCategory::Done, isCompleted: true),
                ],
                'tags' => [
                    self::tag('Planning', 'blue'),
                    self::tag('Admin', 'gray'),
                    self::tag('Urgent', 'red'),
                ],
                'milestones' => [
                    self::milestone('kickoff', 'Kick-off', 'Scope, owners and success measures agreed with everyone who has a say.', 0, 7),
                    self::milestone('midpoint', 'Mid-point review', 'Half the work delivered; scope and dates re-checked against reality.', 8, 30),
                    self::milestone('delivery', 'Delivery', 'Work handed over, signed off and closed out.', 31, 60),
                ],
                'tasks' => [
                    self::task('scope', 'Agree the goal and what is out of scope', 'To do', milestone: 'kickoff', priority: Priority::High, due: 3, estimate: 120,
                        description: 'Write down what this project will deliver and, just as importantly, what it will not. Circulate it before any work starts.',
                        tags: ['Planning'],
                        checklist: ['Problem statement written', 'In scope listed', 'Out of scope listed', 'Circulated for comment']),
                    self::task('stakeholders', 'Identify stakeholders and decision makers', 'To do', milestone: 'kickoff', start: 0, due: 4, estimate: 90, tags: ['Planning']),
                    self::task('success', 'Define what success looks like', 'To do', milestone: 'kickoff', due: 5, estimate: 60, tags: ['Planning'],
                        description: 'Two or three measures that will be true when this is finished. Avoid anything that cannot be checked.'),
                    self::task('plan', 'Build the plan and the schedule', 'To do', milestone: 'kickoff', start: 3, due: 7, estimate: 180, tags: ['Planning'],
                        checklist: ['Milestones agreed', 'Owners assigned', 'Dependencies noted', 'Dates confirmed with owners']),
                    self::task('risks', 'List the risks and who owns each one', 'To do', milestone: 'kickoff', due: 7, estimate: 90),
                    self::task('kickoff-meeting', 'Run the kick-off meeting', 'To do', milestone: 'kickoff', due: 7, estimate: 60, tags: ['Admin']),
                    self::task('workstream-one', 'Deliver the first workstream', 'To do', milestone: 'midpoint', start: 8, due: 22, estimate: 1200),
                    self::task('workstream-two', 'Deliver the second workstream', 'To do', milestone: 'midpoint', start: 12, due: 28, estimate: 1200),
                    self::task('status-updates', 'Send the weekly status update', 'To do', milestone: 'midpoint', start: 8, due: 30, estimate: 30, priority: Priority::Low, tags: ['Admin']),
                    self::task('midpoint-review', 'Hold the mid-point review', 'To do', milestone: 'midpoint', due: 30, estimate: 90,
                        description: 'Compare what was planned against what has happened. Re-plan the second half rather than hoping it catches up.'),
                    self::task('handover', 'Prepare the handover pack', 'To do', milestone: 'delivery', start: 45, due: 56, estimate: 240,
                        checklist: ['Documentation written', 'Owners named', 'Open items listed', 'Access transferred']),
                    self::task('signoff', 'Get sign-off from the sponsor', 'To do', milestone: 'delivery', due: 58, estimate: 60, priority: Priority::High),
                    self::task('retro', 'Run a retrospective and record the lessons', 'To do', milestone: 'delivery', due: 60, estimate: 90, tags: ['Admin']),
                    self::task('close', 'Close the project and archive the files', 'To do', milestone: 'delivery', due: 60, estimate: 60, priority: Priority::Low, tags: ['Admin']),
                ],
                'views' => [
                    self::view('Board', ViewType::Board, groupBy: 'status', pinned: true),
                    self::view('All tasks', ViewType::List, columns: ['title', 'assignee', 'status', 'priority', 'due_date']),
                    self::view('Open work', ViewType::List, filters: ['status' => ['todo', 'in_progress', 'review', 'blocked']], sorts: [self::sort('due_date')]),
                    self::view('Schedule', ViewType::Timeline),
                ],
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Marketing campaign
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private static function marketingCampaign(): array
    {
        return [
            'name' => 'Marketing Campaign',
            'slug' => 'marketing-campaign',
            'description' => 'Brief to wrap-up: audience, budget, creative production, tracking, launch and the post-campaign report.',
            'icon' => '📣',
            'color' => '#C2410C',
            'type' => ProjectType::Marketing,
            'definition' => [
                'statuses' => [
                    self::status('Ideas', StatusCategory::Backlog),
                    self::status('Briefed', StatusCategory::Todo, isDefault: true),
                    self::status('In production', StatusCategory::InProgress),
                    self::status('In review', StatusCategory::Review),
                    self::status('Scheduled', StatusCategory::Todo),
                    self::status('Live', StatusCategory::Done, isCompleted: true),
                ],
                'tags' => [
                    self::tag('Paid media', 'orange'),
                    self::tag('Content', 'blue'),
                    self::tag('Email', 'teal'),
                    self::tag('Social', 'pink'),
                    self::tag('Creative', 'purple'),
                    self::tag('Analytics', 'green'),
                ],
                'milestones' => [
                    self::milestone('brief', 'Brief approved', 'Goal, audience, budget and channels signed off. Nothing is produced before this.', 0, 7),
                    self::milestone('creative', 'Creative and assets complete', 'Every asset written, designed, reviewed and legally cleared.', 8, 28),
                    self::milestone('live', 'Campaign live', 'Everything scheduled, tracking verified, campaign running.', 29, 35),
                    self::milestone('wrap', 'Results reported', 'Performance measured against the KPIs and written up.', 36, 56),
                ],
                'tasks' => [
                    self::task('goals', 'Set the campaign goal and KPIs', 'Briefed', milestone: 'brief', priority: Priority::High, due: 3, estimate: 180,
                        description: 'One primary KPI, at most two secondary. Record today\'s baseline for each so the result means something.',
                        tags: ['Analytics'],
                        checklist: ['Primary KPI agreed', 'Secondary KPIs agreed', 'Baseline recorded', 'Reporting cadence agreed']),
                    self::task('audience', 'Define the target audience and segments', 'Briefed', milestone: 'brief', due: 4, estimate: 240, tags: ['Content'],
                        description: 'Who we are talking to, what they currently believe, and what we want them to do next.'),
                    self::task('budget', 'Agree the budget and the channel split', 'Briefed', milestone: 'brief', priority: Priority::High, due: 5, estimate: 120, tags: ['Paid media'],
                        checklist: ['Total budget confirmed', 'Split per channel', 'Production costs included', 'Contingency held back']),
                    self::task('brief-doc', 'Write and circulate the campaign brief', 'Briefed', milestone: 'brief', start: 3, due: 7, estimate: 180,
                        description: 'The single document everyone works from: goal, audience, message, channels, budget, dates, approvers.',
                        checklist: ['Draft written', 'Reviewed by the channel owners', 'Approved by the sponsor', 'Shared with the agency']),
                    self::task('messaging', 'Develop the messaging and proof points', 'Briefed', milestone: 'creative', start: 8, due: 13, estimate: 300, tags: ['Content'],
                        description: 'The headline claim plus the evidence behind it. Everything downstream reuses these words.'),
                    self::task('concepts', 'Design the creative concepts', 'Briefed', milestone: 'creative', start: 10, due: 16, estimate: 480, tags: ['Creative'],
                        checklist: ['Three routes presented', 'Route chosen', 'Feedback consolidated']),
                    self::task('assets-static', 'Produce the static ad creative', 'Briefed', parent: 'concepts', milestone: 'creative', start: 16, due: 22, estimate: 600, tags: ['Creative', 'Paid media']),
                    self::task('assets-video', 'Produce the video cut-downs', 'Briefed', parent: 'concepts', milestone: 'creative', start: 16, due: 24, estimate: 720, tags: ['Creative']),
                    self::task('landing-copy', 'Write the landing page copy', 'Briefed', milestone: 'creative', start: 12, due: 18, estimate: 300, tags: ['Content']),
                    self::task('landing-build', 'Build and test the landing page', 'Briefed', milestone: 'creative', start: 18, due: 25, estimate: 480, tags: ['Content'],
                        checklist: ['Page built', 'Form submits and routes correctly', 'Mobile checked', 'Page speed under three seconds']),
                    self::task('tracking', 'Set up tracking and the reporting dashboard', 'Briefed', milestone: 'creative', priority: Priority::High, start: 18, due: 26, estimate: 300, tags: ['Analytics'],
                        description: 'If this is wrong, the campaign cannot be judged. Verify with a real test conversion before launch.',
                        checklist: ['UTM convention agreed', 'Conversion events firing', 'Dashboard built', 'Test conversion verified end to end']),
                    self::task('email', 'Build the email sequence', 'Briefed', milestone: 'creative', start: 15, due: 26, estimate: 420, tags: ['Email'],
                        checklist: ['Sequence mapped', 'Copy written', 'Templates built', 'Test send reviewed', 'Suppression list applied']),
                    self::task('social', 'Write and schedule the organic social posts', 'Briefed', milestone: 'creative', start: 20, due: 28, estimate: 300, tags: ['Social']),
                    self::task('paid-setup', 'Set up the paid media campaigns', 'Briefed', milestone: 'creative', start: 22, due: 28, estimate: 420, tags: ['Paid media'],
                        checklist: ['Search campaigns built', 'Paid social campaigns built', 'Audiences uploaded', 'Daily caps set', 'Billing confirmed']),
                    self::task('review', 'Brand, legal and accessibility review', 'In review', milestone: 'creative', priority: Priority::High, start: 26, due: 28, estimate: 180,
                        description: 'Claims substantiated, disclaimers present, contrast and alt text checked. Nothing goes live without this.'),
                    self::task('qa', 'Final pre-launch check of every link and asset', 'In review', milestone: 'live', start: 29, due: 31, estimate: 180,
                        checklist: ['Every link tested', 'Tracking parameters present', 'Assets the right size per placement', 'Send and start times confirmed']),
                    self::task('launch', 'Launch the campaign', 'Scheduled', milestone: 'live', priority: Priority::Urgent, due: 32, estimate: 120),
                    self::task('monitor', 'Monitor performance daily and optimise', 'Scheduled', milestone: 'live', start: 32, due: 35, estimate: 480, tags: ['Paid media', 'Analytics'],
                        description: 'Check spend pacing, creative fatigue and conversion rate every morning for the first week.'),
                    self::task('weekly-report', 'Send the weekly performance report', 'Scheduled', milestone: 'wrap', priority: Priority::Low, start: 36, due: 49, estimate: 120, tags: ['Analytics']),
                    self::task('analysis', 'Analyse the results against the KPIs', 'Scheduled', milestone: 'wrap', start: 50, due: 54, estimate: 300, tags: ['Analytics'],
                        checklist: ['Result against each KPI', 'Cost per acquisition by channel', 'Best and worst creative identified', 'Recommendations written']),
                    self::task('wrapup', 'Write the wrap-up and update the playbook', 'Scheduled', milestone: 'wrap', due: 56, estimate: 240,
                        description: 'What we would repeat, what we would not, and what the next campaign should start from.'),
                    self::task('archive', 'Archive the assets and close the budget', 'Scheduled', milestone: 'wrap', priority: Priority::Low, due: 56, estimate: 90),
                ],
                'views' => [
                    self::view('Campaign board', ViewType::Board, groupBy: 'status', pinned: true),
                    self::view('Content calendar', ViewType::Calendar, pinned: true),
                    self::view('Production schedule', ViewType::Timeline),
                    self::view('Needs attention', ViewType::List,
                        filters: ['priority' => ['high', 'urgent'], 'status' => ['todo', 'in_progress', 'review', 'blocked']],
                        sorts: [self::sort('due_date')],
                        columns: ['title', 'assignee', 'priority', 'due_date', 'status']),
                ],
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Product launch
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private static function productLaunch(): array
    {
        return [
            'name' => 'Product Launch',
            'slug' => 'product-launch',
            'description' => 'Positioning, pricing, beta, enablement and a go/no-go, through launch day to the thirty-day review.',
            'icon' => '🚀',
            'color' => '#7C3AED',
            'type' => ProjectType::ProductLaunch,
            'definition' => [
                'statuses' => [
                    self::status('Backlog', StatusCategory::Backlog),
                    self::status('Planned', StatusCategory::Todo, isDefault: true),
                    self::status('In progress', StatusCategory::InProgress),
                    self::status('In review', StatusCategory::Review),
                    self::status('Blocked', StatusCategory::Blocked),
                    self::status('Launched', StatusCategory::Done, isCompleted: true),
                ],
                'tags' => [
                    self::tag('Go-to-market', 'orange'),
                    self::tag('Pricing', 'green'),
                    self::tag('Enablement', 'blue'),
                    self::tag('Press', 'pink'),
                    self::tag('Docs', 'gray'),
                    self::tag('Engineering', 'brand'),
                ],
                'milestones' => [
                    self::milestone('plan', 'Launch plan approved', 'Positioning, pricing, audience and date agreed by product, marketing and sales.', 0, 10),
                    self::milestone('beta', 'Beta complete', 'Real customers have used it and their feedback has been acted on.', 11, 30),
                    self::milestone('readiness', 'Launch readiness review', 'Every function confirms it is ready. This is where a launch slips, not on launch day.', 31, 55),
                    self::milestone('launch', 'Launch day', 'Announced, available and supported.', 56, 60),
                    self::milestone('review', 'Thirty-day review', 'Adoption, revenue and support load measured against the plan.', 61, 90),
                ],
                'tasks' => [
                    self::task('positioning', 'Agree the positioning and the one-line pitch', 'Planned', milestone: 'plan', priority: Priority::High, due: 5, estimate: 300, tags: ['Go-to-market'],
                        description: 'Who it is for, what it replaces, and why it is better. Everything else is written from this.',
                        checklist: ['Target segment named', 'Alternative it replaces named', 'Three differentiators agreed', 'One-line pitch written']),
                    self::task('audience', 'Size the launch audience and the segments to target first', 'Planned', milestone: 'plan', due: 6, estimate: 180, tags: ['Go-to-market']),
                    self::task('pricing', 'Decide pricing and packaging', 'Planned', milestone: 'plan', priority: Priority::High, start: 2, due: 9, estimate: 480, tags: ['Pricing'],
                        checklist: ['Price points modelled', 'Packaging tiers agreed', 'Discount policy agreed', 'Finance signed off']),
                    self::task('launch-plan', 'Write the launch plan and set the date', 'Planned', milestone: 'plan', start: 6, due: 10, estimate: 240, tags: ['Go-to-market'],
                        checklist: ['Date agreed with engineering', 'Channels chosen', 'Owners named per workstream', 'Risks and fallbacks listed']),
                    self::task('beta-recruit', 'Recruit the beta cohort', 'Planned', milestone: 'beta', start: 11, due: 16, estimate: 240),
                    self::task('beta-run', 'Run the beta and collect structured feedback', 'Planned', milestone: 'beta', start: 16, due: 27, estimate: 720,
                        description: 'Same questions to every participant, so the answers can be compared rather than anecdotally quoted.',
                        checklist: ['Onboarding call per participant', 'Weekly check-in', 'Feedback logged against themes', 'Blocking issues raised to engineering']),
                    self::task('beta-fixes', 'Fix the blocking issues found in beta', 'Planned', milestone: 'beta', priority: Priority::High, start: 20, due: 30, estimate: 960, tags: ['Engineering']),
                    self::task('beta-quotes', 'Collect testimonials and reference customers', 'Planned', milestone: 'beta', start: 25, due: 30, estimate: 180, tags: ['Press']),
                    self::task('website', 'Build the product page and update the pricing page', 'Planned', milestone: 'readiness', start: 31, due: 45, estimate: 720, tags: ['Go-to-market']),
                    self::task('docs', 'Write the product documentation', 'Planned', milestone: 'readiness', start: 31, due: 48, estimate: 960, tags: ['Docs'],
                        checklist: ['Getting started guide', 'Feature reference', 'Limits and known issues', 'Migration notes for existing customers']),
                    self::task('demo', 'Record the demo and the walkthrough video', 'Planned', milestone: 'readiness', start: 38, due: 48, estimate: 480, tags: ['Enablement']),
                    self::task('enablement', 'Enable sales and customer success', 'Planned', milestone: 'readiness', priority: Priority::High, start: 42, due: 52, estimate: 600, tags: ['Enablement'],
                        checklist: ['Pitch deck', 'Objection handling', 'Pricing calculator', 'Live training session held', 'Recording shared']),
                    self::task('support', 'Prepare support: macros, FAQ and escalation path', 'Planned', milestone: 'readiness', start: 45, due: 53, estimate: 360, tags: ['Enablement']),
                    self::task('press', 'Prepare the announcement and brief the press', 'Planned', milestone: 'readiness', start: 45, due: 54, estimate: 420, tags: ['Press'],
                        checklist: ['Press release drafted', 'Embargo date set', 'Analyst briefings booked', 'Customer quote approved']),
                    self::task('telemetry', 'Instrument adoption and revenue reporting', 'Planned', milestone: 'readiness', start: 40, due: 54, estimate: 360, tags: ['Engineering'],
                        description: 'Decide before launch how adoption will be measured, then verify the events actually fire.'),
                    self::task('gonogo', 'Hold the go/no-go review', 'In review', milestone: 'readiness', priority: Priority::Urgent, due: 55, estimate: 120,
                        checklist: ['Engineering ready', 'Support ready', 'Sales enabled', 'Docs published', 'Pricing live in billing', 'Rollback plan agreed']),
                    self::task('launch-day', 'Run launch day', 'Planned', milestone: 'launch', priority: Priority::Urgent, due: 58, estimate: 480,
                        description: 'Publish, announce, watch. Keep the whole launch team on one channel for the first eight hours.',
                        checklist: ['Feature flag on', 'Announcement published', 'Email sent', 'Social posted', 'Support briefed and watching']),
                    self::task('launch-monitor', 'Monitor errors, sign-ups and support volume', 'Planned', milestone: 'launch', priority: Priority::High, start: 58, due: 60, estimate: 360, tags: ['Engineering']),
                    self::task('followup', 'Follow up with every launch-week sign-up', 'Planned', milestone: 'review', start: 61, due: 75, estimate: 480),
                    self::task('review', 'Run the thirty-day review', 'Planned', milestone: 'review', due: 90, estimate: 240,
                        checklist: ['Adoption against target', 'Revenue against target', 'Support load and top issues', 'What to change for the next launch']),
                ],
                'views' => [
                    self::view('Launch board', ViewType::Board, groupBy: 'status', pinned: true),
                    self::view('Countdown', ViewType::Timeline, pinned: true),
                    self::view('Blocking launch', ViewType::List,
                        filters: ['priority' => ['high', 'urgent'], 'status' => ['todo', 'in_progress', 'review', 'blocked']],
                        sorts: [self::sort('due_date')],
                        columns: ['title', 'assignee', 'priority', 'due_date', 'milestone']),
                    self::view('By milestone', ViewType::List, groupBy: 'milestone', sorts: [self::sort('due_date')]),
                ],
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Event planning
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private static function eventPlanning(): array
    {
        return [
            'name' => 'Event Planning',
            'slug' => 'event-planning',
            'description' => 'Budget, venue, speakers, registration and run-of-show, from first idea to the thank-you email.',
            'icon' => '🎪',
            'color' => '#0F766E',
            'type' => ProjectType::Event,
            'definition' => [
                'statuses' => [
                    self::status('Ideas', StatusCategory::Backlog),
                    self::status('To do', StatusCategory::Todo, isDefault: true),
                    self::status('In progress', StatusCategory::InProgress),
                    self::status('Waiting on supplier', StatusCategory::Blocked),
                    self::status('Confirmed', StatusCategory::Review),
                    self::status('Done', StatusCategory::Done, isCompleted: true),
                ],
                'tags' => [
                    self::tag('Venue', 'brand'),
                    self::tag('Catering', 'orange'),
                    self::tag('Speakers', 'purple'),
                    self::tag('Logistics', 'blue'),
                    self::tag('Budget', 'teal'),
                    self::tag('Promotion', 'pink'),
                ],
                'milestones' => [
                    self::milestone('budget', 'Budget approved', 'Format, audience size and spend agreed before anything is booked.', 0, 7),
                    self::milestone('venue', 'Venue and date locked', 'Contract signed, deposit paid, date immovable from here.', 8, 21),
                    self::milestone('programme', 'Programme confirmed', 'Every speaker confirmed in writing with a title and a bio.', 22, 45),
                    self::milestone('registration', 'Registration open', 'People can buy or register, and the confirmation email works.', 46, 50),
                    self::milestone('event', 'Event day', 'It happens.', 51, 90),
                    self::milestone('wrap', 'Wrap-up', 'Invoices paid, feedback collected, lessons written down.', 91, 100),
                ],
                'tasks' => [
                    self::task('objectives', 'Agree the objective and the audience', 'To do', milestone: 'budget', priority: Priority::High, due: 3, estimate: 120,
                        description: 'Why this event exists and who it is for. Attendance targets follow from this, not the other way round.'),
                    self::task('format', 'Choose the format, length and expected headcount', 'To do', milestone: 'budget', due: 5, estimate: 120),
                    self::task('budget', 'Build the budget and get it approved', 'To do', milestone: 'budget', priority: Priority::High, due: 7, estimate: 240, tags: ['Budget'],
                        checklist: ['Venue estimate', 'Catering estimate', 'Speaker costs and travel', 'Production and AV', 'Promotion', 'Ten per cent contingency']),
                    self::task('date', 'Pick the date and check it against the calendar', 'To do', milestone: 'venue', due: 10, estimate: 60,
                        description: 'Check school holidays, public holidays and the two or three competing events your audience also attends.'),
                    self::task('venue-shortlist', 'Shortlist and visit venues', 'To do', milestone: 'venue', start: 8, due: 16, estimate: 480, tags: ['Venue'],
                        checklist: ['Capacity and layout', 'Accessibility', 'AV and power', 'Wi-fi capacity', 'Catering rules', 'Cancellation terms']),
                    self::task('venue-book', 'Sign the venue contract and pay the deposit', 'To do', parent: 'venue-shortlist', milestone: 'venue', priority: Priority::High, due: 21, estimate: 120, tags: ['Venue', 'Budget']),
                    self::task('insurance', 'Arrange insurance and check the risk assessment', 'To do', milestone: 'venue', due: 21, estimate: 120, tags: ['Logistics']),
                    self::task('speakers-longlist', 'Draw up the speaker longlist', 'To do', milestone: 'programme', start: 22, due: 28, estimate: 180, tags: ['Speakers']),
                    self::task('speakers-invite', 'Invite speakers and confirm in writing', 'To do', parent: 'speakers-longlist', milestone: 'programme', priority: Priority::High, start: 28, due: 40, estimate: 360, tags: ['Speakers'],
                        checklist: ['Invitation sent', 'Confirmation received', 'Title and abstract received', 'Bio and photo received', 'Travel booked']),
                    self::task('agenda', 'Build the agenda and the run of show', 'To do', milestone: 'programme', start: 38, due: 45, estimate: 300, tags: ['Logistics'],
                        description: 'Minute by minute for the day, including who is on stage, who is on mic and who is watching the door.'),
                    self::task('catering', 'Confirm catering and dietary requirements', 'To do', milestone: 'programme', start: 30, due: 45, estimate: 180, tags: ['Catering']),
                    self::task('av', 'Book AV, staging and the technical rehearsal', 'To do', milestone: 'programme', start: 30, due: 45, estimate: 240, tags: ['Logistics']),
                    self::task('registration-page', 'Build the registration page and ticketing', 'To do', milestone: 'registration', start: 46, due: 49, estimate: 300,
                        checklist: ['Page live', 'Payment or free registration tested', 'Confirmation email tested', 'Data capture agreed with legal']),
                    self::task('promotion', 'Run the promotion plan', 'To do', milestone: 'registration', start: 50, due: 85, estimate: 720, tags: ['Promotion'],
                        checklist: ['Announcement email', 'Speaker announcement posts', 'Reminder at two weeks', 'Reminder at two days']),
                    self::task('signage', 'Produce signage, badges and printed materials', 'To do', milestone: 'event', start: 70, due: 85, estimate: 300, tags: ['Logistics']),
                    self::task('briefing', 'Brief the on-the-day team', 'To do', milestone: 'event', priority: Priority::High, start: 86, due: 89, estimate: 120, tags: ['Logistics'],
                        checklist: ['Roles assigned', 'Run of show shared', 'Emergency procedure covered', 'Contact list circulated']),
                    self::task('rehearsal', 'Hold the technical rehearsal', 'To do', milestone: 'event', due: 89, estimate: 240, tags: ['Logistics']),
                    self::task('event-day', 'Run the event', 'To do', milestone: 'event', priority: Priority::Urgent, due: 90, estimate: 600),
                    self::task('thanks', 'Send the thank-you email and the recordings', 'To do', milestone: 'wrap', start: 91, due: 93, estimate: 120, tags: ['Promotion']),
                    self::task('feedback', 'Collect and summarise attendee feedback', 'To do', milestone: 'wrap', start: 91, due: 96, estimate: 180),
                    self::task('invoices', 'Settle invoices and reconcile against the budget', 'To do', milestone: 'wrap', due: 100, estimate: 240, tags: ['Budget']),
                    self::task('debrief', 'Run the debrief and write the lessons down', 'To do', milestone: 'wrap', due: 100, estimate: 120),
                ],
                'views' => [
                    self::view('Planning board', ViewType::Board, groupBy: 'status', pinned: true),
                    self::view('Run of show', ViewType::Calendar, pinned: true),
                    self::view('Countdown', ViewType::Timeline),
                    self::view('Waiting on suppliers', ViewType::List, filters: ['status' => ['blocked']], sorts: [self::sort('due_date')]),
                ],
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Website project
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private static function websiteProject(): array
    {
        return [
            'name' => 'Website Project',
            'slug' => 'website-project',
            'description' => 'Discovery, design, build, content and launch — including accessibility, SEO redirects and the DNS cutover.',
            'icon' => '🌐',
            'color' => '#1D4ED8',
            'type' => ProjectType::Creative,
            'definition' => [
                'statuses' => [
                    self::status('Backlog', StatusCategory::Backlog),
                    self::status('To do', StatusCategory::Todo, isDefault: true),
                    self::status('Design', StatusCategory::InProgress),
                    self::status('Build', StatusCategory::InProgress),
                    self::status('QA', StatusCategory::Review),
                    self::status('Blocked', StatusCategory::Blocked),
                    self::status('Done', StatusCategory::Done, isCompleted: true),
                ],
                'tags' => [
                    self::tag('Design', 'pink'),
                    self::tag('Content', 'blue'),
                    self::tag('Development', 'brand'),
                    self::tag('SEO', 'green'),
                    self::tag('Accessibility', 'purple'),
                ],
                'milestones' => [
                    self::milestone('discovery', 'Discovery complete', 'Goals, audiences, sitemap and requirements agreed and written down.', 0, 14),
                    self::milestone('design', 'Design signed off', 'Every template designed, reviewed and approved in writing.', 15, 35),
                    self::milestone('build', 'Build complete', 'Templates built, integrated with the CMS and passing QA.', 36, 70),
                    self::milestone('content', 'Content loaded', 'Every page written, proofed and in the CMS.', 60, 80),
                    self::milestone('launch', 'Launch', 'Live on the real domain, with redirects and analytics working.', 81, 90),
                ],
                'tasks' => [
                    self::task('goals', 'Agree the goals and the primary calls to action', 'To do', milestone: 'discovery', priority: Priority::High, due: 4, estimate: 180,
                        description: 'What should a visitor do? Every later decision is settled by referring back to this.'),
                    self::task('interviews', 'Interview stakeholders and summarise the findings', 'To do', milestone: 'discovery', start: 1, due: 8, estimate: 480),
                    self::task('audit', 'Audit the current site: pages, traffic and content quality', 'To do', milestone: 'discovery', start: 1, due: 9, estimate: 480, tags: ['Content', 'SEO'],
                        checklist: ['Page inventory exported', 'Traffic per page pulled', 'Keep, rewrite or retire decided per page']),
                    self::task('sitemap', 'Produce the sitemap and the navigation model', 'To do', milestone: 'discovery', start: 8, due: 12, estimate: 300, tags: ['Content']),
                    self::task('requirements', 'Write the technical and functional requirements', 'To do', milestone: 'discovery', due: 14, estimate: 300, tags: ['Development'],
                        checklist: ['CMS chosen', 'Integrations listed', 'Hosting decided', 'Performance budget set', 'Accessibility target set']),
                    self::task('wireframes', 'Wireframe the key templates', 'To do', milestone: 'design', start: 15, due: 22, estimate: 600, tags: ['Design']),
                    self::task('designsystem', 'Build the design system: type, colour, spacing, components', 'To do', milestone: 'design', start: 18, due: 28, estimate: 720, tags: ['Design'],
                        checklist: ['Type scale', 'Colour palette with contrast checked', 'Spacing scale', 'Core components', 'Dark mode decided']),
                    self::task('designs', 'Design every page template', 'To do', parent: 'designsystem', milestone: 'design', start: 24, due: 33, estimate: 960, tags: ['Design']),
                    self::task('design-signoff', 'Get written design sign-off', 'To do', milestone: 'design', priority: Priority::High, due: 35, estimate: 120, tags: ['Design']),
                    self::task('scaffold', 'Set up the repository, environments and deploy pipeline', 'To do', milestone: 'build', start: 30, due: 40, estimate: 480, tags: ['Development']),
                    self::task('cms', 'Model the content types in the CMS', 'To do', milestone: 'build', start: 36, due: 46, estimate: 600, tags: ['Development', 'Content']),
                    self::task('templates', 'Build the page templates', 'To do', milestone: 'build', start: 42, due: 64, estimate: 1920, tags: ['Development']),
                    self::task('responsive', 'Check every template at every breakpoint', 'QA', milestone: 'build', start: 60, due: 68, estimate: 480, tags: ['Development']),
                    self::task('accessibility', 'Accessibility audit against WCAG 2.2 AA', 'QA', milestone: 'build', priority: Priority::High, start: 62, due: 70, estimate: 480, tags: ['Accessibility'],
                        description: 'Keyboard only, screen reader, contrast, focus order, form labels, motion preferences.',
                        checklist: ['Keyboard navigation', 'Screen reader pass', 'Contrast checked', 'Focus visible everywhere', 'Forms labelled and errors announced']),
                    self::task('performance', 'Meet the performance budget', 'QA', milestone: 'build', start: 62, due: 70, estimate: 360, tags: ['Development']),
                    self::task('copywriting', 'Write the page copy', 'To do', milestone: 'content', start: 60, due: 74, estimate: 1440, tags: ['Content']),
                    self::task('images', 'Source, crop and compress the imagery', 'To do', milestone: 'content', start: 64, due: 76, estimate: 480, tags: ['Design']),
                    self::task('load-content', 'Load the content into the CMS and proof it', 'To do', parent: 'copywriting', milestone: 'content', start: 70, due: 80, estimate: 720, tags: ['Content']),
                    self::task('redirects', 'Build and test the redirect map', 'To do', milestone: 'launch', priority: Priority::High, start: 76, due: 84, estimate: 360, tags: ['SEO'],
                        description: 'Every old URL with traffic or a backlink maps to its new home. This is where search rankings are lost.',
                        checklist: ['Old URLs exported', 'Destination decided per URL', 'Redirects implemented', 'Tested from the live sitemap']),
                    self::task('analytics', 'Set up analytics, search console and cookie consent', 'To do', milestone: 'launch', start: 80, due: 86, estimate: 300, tags: ['SEO']),
                    self::task('uat', 'Run user acceptance testing and fix what comes back', 'QA', milestone: 'launch', start: 82, due: 88, estimate: 600),
                    self::task('launch-checklist', 'Work through the launch checklist', 'To do', milestone: 'launch', priority: Priority::Urgent, due: 89, estimate: 240,
                        checklist: ['Backup taken', 'SSL certificate valid', 'robots.txt correct', 'Sitemap submitted', '404 page in place', 'Forms tested on production']),
                    self::task('dns', 'Cut over DNS and monitor', 'To do', milestone: 'launch', priority: Priority::Urgent, due: 90, estimate: 180, tags: ['Development']),
                    self::task('post-launch', 'Monitor errors, traffic and forms for two weeks', 'To do', milestone: 'launch', start: 90, due: 90, estimate: 360),
                ],
                'views' => [
                    self::view('Board', ViewType::Board, groupBy: 'status', pinned: true),
                    self::view('Delivery plan', ViewType::Timeline, pinned: true),
                    self::view('In QA', ViewType::List, filters: ['status' => ['review']], sorts: [self::sort('due_date')]),
                    self::view('Content workload', ViewType::List, groupBy: 'assignee', sorts: [self::sort('due_date')], columns: ['title', 'assignee', 'status', 'due_date']),
                ],
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Software project
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private static function softwareProject(): array
    {
        return [
            'name' => 'Software Project',
            'slug' => 'software-project',
            'description' => 'Technical design through to production release, with code review, QA, a code freeze and a rollback plan.',
            'icon' => '💻',
            'color' => '#0369A1',
            'type' => ProjectType::Software,
            'definition' => [
                'statuses' => [
                    self::status('Backlog', StatusCategory::Backlog),
                    self::status('To do', StatusCategory::Todo, isDefault: true),
                    self::status('In progress', StatusCategory::InProgress),
                    self::status('Code review', StatusCategory::Review),
                    self::status('QA', StatusCategory::Review),
                    self::status('Blocked', StatusCategory::Blocked),
                    self::status('Done', StatusCategory::Done, isCompleted: true),
                ],
                'tags' => [
                    self::tag('Frontend', 'pink'),
                    self::tag('Backend', 'brand'),
                    self::tag('Infrastructure', 'gray'),
                    self::tag('Bug', 'red'),
                    self::tag('Tech debt', 'amber'),
                    self::tag('Security', 'purple'),
                ],
                'milestones' => [
                    self::milestone('design', 'Technical design agreed', 'Approach, data model and interfaces written down and reviewed.', 0, 10),
                    self::milestone('mvp', 'Feature complete', 'Every planned behaviour implemented behind a flag.', 11, 45),
                    self::milestone('freeze', 'Code freeze', 'Only fixes from here. New work waits for the next release.', 46, 60),
                    self::milestone('rc', 'Release candidate', 'Deployed to staging, tested end to end, release notes written.', 61, 70),
                    self::milestone('release', 'Production release', 'Shipped, monitored and handed to support.', 71, 80),
                ],
                'tasks' => [
                    self::task('requirements', 'Write the requirements and the acceptance criteria', 'To do', milestone: 'design', priority: Priority::High, due: 4, estimate: 300,
                        description: 'Behaviour, not implementation. Each criterion must be something a test can assert.'),
                    self::task('adr', 'Write the technical design and record the decisions', 'To do', milestone: 'design', start: 2, due: 8, estimate: 480, tags: ['Backend'],
                        checklist: ['Approach chosen and alternatives noted', 'Data model drafted', 'Interfaces defined', 'Failure modes considered', 'Reviewed by a second engineer']),
                    self::task('schema', 'Design the database schema and the migrations', 'To do', milestone: 'design', start: 5, due: 10, estimate: 360, tags: ['Backend']),
                    self::task('estimates', 'Break the work down and estimate it', 'To do', milestone: 'design', due: 10, estimate: 180),
                    self::task('ci', 'Set up CI, static analysis and the test harness', 'To do', milestone: 'mvp', start: 11, due: 18, estimate: 480, tags: ['Infrastructure'],
                        checklist: ['Build runs on every push', 'Tests run in CI', 'Static analysis wired in', 'Coverage reported']),
                    self::task('auth', 'Implement authentication and authorization', 'To do', milestone: 'mvp', priority: Priority::High, start: 14, due: 26, estimate: 960, tags: ['Backend', 'Security']),
                    self::task('api', 'Build the core API endpoints', 'To do', milestone: 'mvp', start: 18, due: 36, estimate: 1920, tags: ['Backend']),
                    self::task('ui', 'Build the user interface', 'To do', milestone: 'mvp', start: 22, due: 42, estimate: 1920, tags: ['Frontend']),
                    self::task('tests', 'Write the automated test suite', 'To do', milestone: 'mvp', start: 20, due: 45, estimate: 1440,
                        description: 'Unit tests for the rules, feature tests for the flows, and one test per bug that reaches production.'),
                    self::task('errors', 'Wire up error tracking and structured logging', 'To do', milestone: 'mvp', start: 30, due: 44, estimate: 240, tags: ['Infrastructure']),
                    self::task('security-review', 'Security review and dependency audit', 'Code review', milestone: 'freeze', priority: Priority::High, start: 46, due: 54, estimate: 480, tags: ['Security'],
                        checklist: ['Dependencies audited', 'Authorization checked on every route', 'Input validation reviewed', 'Secrets kept out of logs']),
                    self::task('perf', 'Profile and fix the slow paths', 'To do', milestone: 'freeze', start: 46, due: 56, estimate: 600, tags: ['Backend']),
                    self::task('debt', 'Clear the tech debt agreed for this release', 'To do', milestone: 'freeze', priority: Priority::Low, start: 46, due: 58, estimate: 480, tags: ['Tech debt']),
                    self::task('freeze', 'Declare code freeze', 'To do', milestone: 'freeze', priority: Priority::High, due: 60, estimate: 30),
                    self::task('staging', 'Deploy the release candidate to staging', 'To do', milestone: 'rc', start: 61, due: 63, estimate: 240, tags: ['Infrastructure']),
                    self::task('regression', 'Run the regression pass on staging', 'QA', milestone: 'rc', start: 63, due: 68, estimate: 720),
                    self::task('load', 'Run a load test against staging', 'QA', milestone: 'rc', start: 64, due: 68, estimate: 360, tags: ['Infrastructure']),
                    self::task('docs', 'Update the documentation and write the release notes', 'To do', milestone: 'rc', start: 64, due: 70, estimate: 360),
                    self::task('runbook', 'Write the deployment runbook and the rollback plan', 'To do', milestone: 'release', priority: Priority::High, start: 66, due: 74, estimate: 300, tags: ['Infrastructure'],
                        description: 'Written before the deploy, not during it. Include how to reverse every step.',
                        checklist: ['Deploy steps listed', 'Migration plan including rollback', 'Feature flag plan', 'Who to call']),
                    self::task('release', 'Release to production', 'To do', milestone: 'release', priority: Priority::Urgent, due: 76, estimate: 240, tags: ['Infrastructure']),
                    self::task('monitor', 'Watch errors and latency for the first week', 'To do', milestone: 'release', priority: Priority::High, start: 76, due: 80, estimate: 300, tags: ['Infrastructure']),
                    self::task('retro', 'Run the release retrospective', 'To do', milestone: 'release', priority: Priority::Low, due: 80, estimate: 90),
                ],
                'views' => [
                    self::view('Sprint board', ViewType::Board, groupBy: 'status', pinned: true),
                    self::view('My open work', ViewType::List, filters: ['status' => ['todo', 'in_progress', 'review', 'blocked']], sorts: [self::sort('priority', 'desc'), self::sort('due_date')]),
                    self::view('Blocked', ViewType::List, filters: ['status' => ['blocked']], sorts: [self::sort('due_date')]),
                    self::view('Release plan', ViewType::Timeline),
                ],
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Hiring project
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private static function hiringProject(): array
    {
        return [
            'name' => 'Hiring Project',
            'slug' => 'hiring-project',
            'description' => 'One role from approved headcount to day one: scorecards, sourcing, interview loop, offer and onboarding.',
            'icon' => '🧑‍💼',
            'color' => '#B45309',
            'type' => ProjectType::Hr,
            'definition' => [
                'statuses' => [
                    self::status('Backlog', StatusCategory::Backlog),
                    self::status('To do', StatusCategory::Todo, isDefault: true),
                    self::status('In progress', StatusCategory::InProgress),
                    self::status('Waiting on candidate', StatusCategory::Blocked),
                    self::status('Waiting on hiring manager', StatusCategory::Blocked),
                    self::status('Done', StatusCategory::Done, isCompleted: true),
                ],
                'tags' => [
                    self::tag('Sourcing', 'blue'),
                    self::tag('Interviewing', 'purple'),
                    self::tag('Offer', 'green'),
                    self::tag('Onboarding', 'teal'),
                    self::tag('Compliance', 'gray'),
                ],
                'milestones' => [
                    self::milestone('approved', 'Role approved', 'Headcount, budget and level signed off before anyone is contacted.', 0, 5),
                    self::milestone('posted', 'Role live', 'Job description published and sourcing under way.', 6, 10),
                    self::milestone('shortlist', 'Shortlist ready', 'Screened candidates ready for the hiring manager.', 11, 25),
                    self::milestone('interviews', 'Interviews complete', 'Full loop done and the panel has a decision.', 26, 45),
                    self::milestone('offer', 'Offer accepted', 'Signed contract and an agreed start date.', 46, 55),
                    self::milestone('onboard', 'Day one', 'New joiner has access, a plan and someone to ask.', 56, 80),
                ],
                'tasks' => [
                    self::task('headcount', 'Confirm headcount, level and salary band', 'To do', milestone: 'approved', priority: Priority::High, due: 3, estimate: 120, tags: ['Compliance']),
                    self::task('scorecard', 'Write the scorecard for the role', 'To do', milestone: 'approved', priority: Priority::High, due: 5, estimate: 180, tags: ['Interviewing'],
                        description: 'The outcomes this person must deliver in their first year, and the competencies each interview will test.',
                        checklist: ['Outcomes listed', 'Competencies listed', 'Which interview tests which competency', 'Agreed with the hiring manager']),
                    self::task('jd', 'Write the job description', 'To do', milestone: 'approved', due: 5, estimate: 180,
                        checklist: ['Responsibilities', 'Requirements separated from nice-to-haves', 'Salary range included', 'Inclusive language checked']),
                    self::task('loop', 'Design the interview loop and brief the panel', 'To do', milestone: 'posted', start: 6, due: 10, estimate: 180, tags: ['Interviewing'],
                        checklist: ['Stages agreed', 'Interviewers named', 'Questions written per stage', 'Panel briefed on the scorecard']),
                    self::task('post', 'Publish the role and open the pipeline', 'To do', milestone: 'posted', due: 8, estimate: 120, tags: ['Sourcing'],
                        checklist: ['Careers page', 'Job boards', 'Internal announcement', 'Referral request to the team']),
                    self::task('agency', 'Brief the agency or sourcing partner', 'To do', milestone: 'posted', priority: Priority::Low, due: 10, estimate: 90, tags: ['Sourcing']),
                    self::task('outbound', 'Run outbound sourcing', 'To do', milestone: 'shortlist', start: 11, due: 24, estimate: 720, tags: ['Sourcing']),
                    self::task('screen', 'Review applications and screen candidates', 'To do', milestone: 'shortlist', start: 11, due: 24, estimate: 600, tags: ['Sourcing'],
                        description: 'Score against the scorecard, not against a feeling. Reply to everyone, including the people you reject.'),
                    self::task('shortlist', 'Present the shortlist to the hiring manager', 'To do', parent: 'screen', milestone: 'shortlist', priority: Priority::High, due: 25, estimate: 120),
                    self::task('first-round', 'Run the first-round interviews', 'To do', milestone: 'interviews', start: 26, due: 36, estimate: 720, tags: ['Interviewing']),
                    self::task('exercise', 'Run the work sample or case exercise', 'To do', milestone: 'interviews', start: 30, due: 40, estimate: 480, tags: ['Interviewing'],
                        description: 'Timeboxed, paid where appropriate, and marked against a rubric written before the first candidate sees it.'),
                    self::task('panel', 'Run the final panel and debrief', 'To do', milestone: 'interviews', priority: Priority::High, start: 38, due: 45, estimate: 480, tags: ['Interviewing'],
                        checklist: ['Every interviewer submits their scorecard before the debrief', 'Debrief held', 'Decision recorded with reasons']),
                    self::task('references', 'Take references', 'Waiting on candidate', milestone: 'offer', start: 46, due: 50, estimate: 180, tags: ['Compliance']),
                    self::task('checks', 'Run right-to-work and background checks', 'Waiting on candidate', milestone: 'offer', priority: Priority::High, start: 46, due: 52, estimate: 120, tags: ['Compliance']),
                    self::task('offer', 'Make the offer', 'To do', milestone: 'offer', priority: Priority::High, due: 50, estimate: 120, tags: ['Offer'],
                        checklist: ['Verbal offer made', 'Written offer sent', 'Questions answered', 'Deadline agreed']),
                    self::task('contract', 'Issue the contract and get it signed', 'Waiting on candidate', milestone: 'offer', due: 55, estimate: 120, tags: ['Offer', 'Compliance']),
                    self::task('rejections', 'Close out the other candidates properly', 'To do', milestone: 'offer', priority: Priority::Low, due: 55, estimate: 120,
                        description: 'Personal, prompt and specific. Today\'s runner-up is next year\'s hire.'),
                    self::task('onboarding-plan', 'Write the thirty, sixty and ninety day plan', 'To do', milestone: 'onboard', start: 56, due: 70, estimate: 240, tags: ['Onboarding']),
                    self::task('equipment', 'Order equipment and request system access', 'To do', milestone: 'onboard', priority: Priority::High, start: 56, due: 72, estimate: 120, tags: ['Onboarding'],
                        checklist: ['Laptop ordered', 'Accounts requested', 'Desk or remote kit arranged', 'Payroll set up']),
                    self::task('buddy', 'Assign a buddy and book the first-week meetings', 'To do', milestone: 'onboard', start: 60, due: 76, estimate: 90, tags: ['Onboarding']),
                    self::task('day-one', 'Run day one', 'To do', milestone: 'onboard', priority: Priority::High, due: 80, estimate: 240, tags: ['Onboarding']),
                ],
                'views' => [
                    self::view('Hiring board', ViewType::Board, groupBy: 'status', pinned: true),
                    self::view('Waiting on someone', ViewType::List, filters: ['status' => ['blocked']], sorts: [self::sort('due_date')]),
                    self::view('This stage', ViewType::List, filters: ['status' => ['todo', 'in_progress']], sorts: [self::sort('due_date')], columns: ['title', 'assignee', 'status', 'due_date']),
                    self::view('Hiring timeline', ViewType::Timeline),
                ],
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Row builders
     *
     * The definition is plain JSON by the time it reaches the database, so these
     * exist only to keep the blueprints above readable and their keys consistent
     * with what CreateProjectFromTemplate reads.
     * ------------------------------------------------------------------ */

    /**
     * @return array{name: string, color: string, category: string, is_default: bool, is_completed: bool}
     */
    private static function status(
        string $name,
        StatusCategory $category,
        bool $isDefault = false,
        ?bool $isCompleted = null,
    ): array {
        return [
            'name' => $name,
            'color' => $category->color(),
            'category' => $category->value,
            'is_default' => $isDefault,
            'is_completed' => $isCompleted ?? $category->isClosed(),
        ];
    }

    /**
     * @return array{name: string, color: string}
     */
    private static function tag(string $name, string $color): array
    {
        return ['name' => $name, 'color' => $color];
    }

    /**
     * @return array{ref: string, name: string, description: string, status: string, start_offset_days: int, due_offset_days: int}
     */
    private static function milestone(
        string $ref,
        string $name,
        string $description,
        int $start,
        int $due,
    ): array {
        return [
            'ref' => $ref,
            'name' => $name,
            'description' => $description,
            'status' => MilestoneStatus::Planned->value,
            'start_offset_days' => $start,
            'due_offset_days' => $due,
        ];
    }

    /**
     * @param list<string> $tags
     * @param list<string> $checklist
     * @return array<string, mixed>
     */
    private static function task(
        string $ref,
        string $title,
        string $status,
        ?string $milestone = null,
        ?string $parent = null,
        Priority $priority = Priority::Medium,
        ?int $start = null,
        ?int $due = null,
        ?int $estimate = null,
        ?string $description = null,
        array $tags = [],
        array $checklist = [],
    ): array {
        $row = [
            'ref' => $ref,
            'title' => $title,
            'status' => $status,
            'priority' => $priority->value,
        ];

        if ($description !== null) {
            $row['description'] = $description;
        }

        if ($milestone !== null) {
            $row['milestone'] = $milestone;
        }

        if ($parent !== null) {
            $row['parent'] = $parent;
        }

        if ($start !== null) {
            $row['start_offset_days'] = $start;
        }

        if ($due !== null) {
            $row['due_offset_days'] = $due;
        }

        if ($estimate !== null) {
            $row['estimate_minutes'] = $estimate;
        }

        if ($tags !== []) {
            $row['tags'] = $tags;
        }

        if ($checklist !== []) {
            $row['checklist'] = $checklist;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<array{field: string, direction: string}>|null $sorts
     * @param list<string>|null $columns
     * @return array<string, mixed>
     */
    private static function view(
        string $name,
        ViewType $type,
        array $filters = [],
        ?array $sorts = null,
        ?array $columns = null,
        ?string $groupBy = null,
        bool $pinned = false,
    ): array {
        $row = [
            'name' => $name,
            'type' => $type->value,
            'filters' => $filters,
            'is_shared' => true,
            'is_pinned' => $pinned,
        ];

        if ($sorts !== null) {
            $row['sorts'] = $sorts;
        }

        if ($columns !== null) {
            $row['columns'] = $columns;
        }

        if ($groupBy !== null) {
            $row['group_by'] = $groupBy;
        }

        return $row;
    }

    /**
     * @return array{field: string, direction: string}
     */
    private static function sort(string $field, string $direction = 'asc'): array
    {
        return ['field' => $field, 'direction' => $direction];
    }
}
