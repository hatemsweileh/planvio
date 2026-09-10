<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AutomationTrigger;
use App\Enums\WorkspaceRole;
use App\Filament\Pages\MaintenanceMode;
use App\Filament\Resources\AiAutomations\Pages\CreateAiAutomation;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Resources\AiProviders\Pages\CreateAiProvider;
use App\Filament\Resources\AiProviders\Pages\EditAiProvider;
use App\Filament\Resources\AiProviders\Pages\ListAiProviders;
use App\Filament\Resources\AiRuns\AiRunResource;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Settings\Pages\CreateSetting;
use App\Filament\Resources\Settings\Pages\EditSetting;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Workspaces\Pages\ListWorkspaces;
use App\Filament\Resources\Workspaces\WorkspaceResource;
use App\Models\AiAutomation;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\TwoFactorService;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the panel's controls actually do.
 *
 * Rendering is covered by {@see AdminPanelTest}; this is about consequences — the row that
 * changes, the audit entry that is written, the guard that refuses. These are the operations
 * nobody else in the installation can perform, so each one is asserted rather than assumed.
 *
 * @fixture-secrets The provider API keys below are invented placeholders. Proving that a
 * saved key is kept intact, and that a blank submission does not overwrite it, needs a key
 * to save. Read by the release script's secret sweep; only honoured under `tests/`.
 */
final class AdminActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($this->admin);

        // The panel middleware does this on every HTTP request. A Livewire component driven
        // directly never passes through it, and Filament has no default panel configured -
        // deliberately, so nothing outside /admin resolves to it by accident.
        Filament::setCurrentPanel('admin');
    }

    /* ------------------------------------------------------------------ *
     * Accounts
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_deactivates_an_account_and_records_it(): void
    {
        $member = User::factory()->create(['is_active' => true]);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('toggleActive')->table($member));

        $this->assertFalse($member->fresh()->is_active);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->getKey(),
            'event' => 'admin.user_deactivated',
        ]);
    }

    #[Test]
    public function it_will_not_let_an_administrator_deactivate_themselves(): void
    {
        Livewire::test(ListUsers::class)
            ->assertActionHidden(TestAction::make('toggleActive')->table($this->admin));
    }

    #[Test]
    public function it_will_not_deactivate_the_last_active_platform_administrator(): void
    {
        $other = User::factory()->create(['is_admin' => true, 'is_active' => true]);

        // With two administrators the action is available; the guard is about the last one.
        Livewire::test(ListUsers::class)
            ->assertActionVisible(TestAction::make('toggleActive')->table($other));

        $this->admin->forceFill(['is_admin' => false])->save();
        $this->actingAs($other);

        // `$other` is now the only administrator left, so nobody may switch them off. Asserted
        // through the resource's own guard rather than the button, because it is the guard the
        // delete action consults too.
        $this->assertTrue(UserResource::isLastAdministrator($other->fresh()));
    }

    #[Test]
    public function it_forces_a_password_reset_by_invalidating_the_current_password(): void
    {
        Notification::fake();

        $member = User::factory()->create(['password' => 'CorrectHorse1!']);
        $original = $member->password;

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('forcePasswordReset')->table($member));

        $member->refresh();

        $this->assertNotSame($original, $member->password);
        $this->assertFalse(Hash::check('CorrectHorse1!', (string) $member->password));

        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.user_password_reset_forced']);
    }

    #[Test]
    public function it_resets_two_factor_authentication(): void
    {
        $twoFactor = app(TwoFactorService::class);
        $member = User::factory()->create();

        $twoFactor->enable($member, $twoFactor->generateSecret(), $twoFactor->generateRecoveryCodes());
        $twoFactor->confirm($member);

        $this->assertTrue($member->fresh()->hasTwoFactorEnabled());

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('resetTwoFactor')->table($member->fresh()));

        $this->assertFalse($member->fresh()->hasTwoFactorEnabled());
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.user_two_factor_reset']);
    }

    #[Test]
    public function editing_an_account_without_touching_the_password_leaves_it_alone(): void
    {
        $member = User::factory()->create(['name' => 'Original name', 'password' => 'CorrectHorse1!']);
        $hash = $member->password;

        Livewire::test(EditUser::class, ['record' => $member->getKey()])
            ->fillForm(['name' => 'Corrected name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $member->refresh();

        $this->assertSame('Corrected name', $member->name);
        $this->assertSame($hash, $member->password);
        $this->assertTrue(Hash::check('CorrectHorse1!', (string) $member->password));
    }

    #[Test]
    public function an_administrator_cannot_revoke_their_own_platform_access(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->admin->getKey()])
            ->assertFormFieldDisabled('is_admin')
            ->assertFormFieldDisabled('is_active')
            ->fillForm(['is_admin' => false, 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->admin->refresh();

        $this->assertTrue($this->admin->is_admin);
        $this->assertTrue($this->admin->is_active);
    }

    /* ------------------------------------------------------------------ *
     * Workspaces
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_suspends_and_reopens_a_workspace(): void
    {
        $workspace = $this->makeWorkspace();

        Livewire::test(ListWorkspaces::class)
            ->callAction(TestAction::make('toggleSuspension')->table($workspace));

        $this->assertTrue($workspace->fresh()->is_suspended);

        Livewire::test(ListWorkspaces::class)
            ->callAction(TestAction::make('toggleSuspension')->table($workspace->fresh()));

        $this->assertFalse($workspace->fresh()->is_suspended);

        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.workspace_suspended']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.workspace_unsuspended']);
    }

    #[Test]
    public function it_transfers_ownership_to_an_existing_member(): void
    {
        $workspace = $this->makeWorkspace();
        $successor = $this->makeMember($workspace, WorkspaceRole::Admin);

        Livewire::test(ListWorkspaces::class)
            ->callAction(
                TestAction::make('transferOwnership')->table($workspace),
                ['user_id' => $successor->getKey()],
            );

        $workspace->refresh();

        $this->assertSame($successor->getKey(), $workspace->owner_id);
        $this->assertSame(WorkspaceRole::Owner, $successor->fresh()->roleIn($workspace));
    }

    #[Test]
    public function it_transfers_ownership_to_somebody_who_is_not_yet_a_member_by_adding_them(): void
    {
        $workspace = $this->makeWorkspace();
        $outsider = User::factory()->create();

        Livewire::test(ListWorkspaces::class)
            ->callAction(
                TestAction::make('transferOwnership')->table($workspace),
                ['user_id' => $outsider->getKey()],
            );

        $workspace->refresh();

        $this->assertSame($outsider->getKey(), $workspace->owner_id);

        // The membership row is the point: no path in this panel grants access invisibly.
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->getKey(),
            'user_id' => $outsider->getKey(),
            'role' => WorkspaceRole::Owner->value,
        ]);
    }

    #[Test]
    public function it_soft_deletes_a_workspace_and_leaves_its_records_intact(): void
    {
        $workspace = $this->makeWorkspace();
        $member = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->makeProject($workspace);
        $task = $this->makeTask($project);

        Livewire::test(ListWorkspaces::class)
            ->callAction(TestAction::make('deleteWorkspace')->table($workspace));

        $this->assertSoftDeleted('workspaces', ['id' => $workspace->getKey()]);
        $this->assertDatabaseHas('workspaces', ['id' => $workspace->getKey(), 'is_suspended' => true]);

        // Nothing beneath the workspace was touched.
        $this->assertDatabaseHas('projects', ['id' => $project->getKey()]);
        $this->assertDatabaseHas('tasks', ['id' => $task->getKey()]);
        $this->assertDatabaseHas('workspace_members', ['user_id' => $member->getKey()]);
    }

    #[Test]
    public function it_restores_a_deleted_workspace_still_suspended(): void
    {
        $workspace = $this->makeWorkspace();
        $workspace->is_suspended = true;
        $workspace->save();
        $workspace->delete();

        Livewire::test(ListWorkspaces::class)
            ->callAction(TestAction::make('restoreWorkspace')->table(
                Workspace::withTrashed()->findOrFail($workspace->getKey()),
            ));

        $restored = Workspace::query()->find($workspace->getKey());

        $this->assertNotNull($restored);
        $this->assertTrue($restored->is_suspended);
    }

    /* ------------------------------------------------------------------ *
     * AI providers
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_tests_a_provider_connection_and_records_the_outcome_without_the_key(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'gpt-4o-mini',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 1],
        ])]);

        $provider = AiProvider::factory()->active()->create([
            'api_key' => 'sk-connectiontestsecret000000000000',
        ]);

        Livewire::test(ListAiProviders::class)
            ->callAction(TestAction::make('testConnection')->table($provider));

        $entry = AuditLog::query()->where('event', 'admin.ai_provider_tested')->firstOrFail();

        $this->assertTrue((bool) ($entry->properties['ok'] ?? false));
        $this->assertStringNotContainsString(
            'sk-connectiontestsecret000000000000',
            (string) json_encode($entry->properties),
        );
    }

    #[Test]
    public function it_reports_an_unreachable_provider_rather_than_throwing(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('nope', 401)]);

        $provider = AiProvider::factory()->active()->create();

        Livewire::test(ListAiProviders::class)
            ->callAction(TestAction::make('testConnection')->table($provider));

        $entry = AuditLog::query()->where('event', 'admin.ai_provider_tested')->firstOrFail();

        $this->assertFalse((bool) ($entry->properties['ok'] ?? true));
    }

    #[Test]
    public function it_keeps_exactly_one_default_provider(): void
    {
        $existing = AiProvider::factory()->default()->create();

        Livewire::test(CreateAiProvider::class)
            ->fillForm([
                'name' => 'Second provider',
                'driver' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4o-mini',
                'api_key' => 'sk-secondprovider00000000000000000',
                'timeout_seconds' => 60,
                'is_active' => true,
                'is_default' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse($existing->fresh()->is_default);
        $this->assertSame(1, AiProvider::query()->where('is_default', true)->count());
    }

    #[Test]
    public function a_blank_api_key_on_edit_keeps_the_stored_one(): void
    {
        $provider = AiProvider::factory()->create(['api_key' => 'sk-keepthisexactkey0000000000000000']);

        // Only the name is filled: the credential field is left exactly as the form renders it,
        // which is empty, and that must not be read as "clear the key".
        Livewire::test(EditAiProvider::class, ['record' => $provider->getKey()])
            ->fillForm(['name' => 'Renamed provider'])
            ->call('save')
            ->assertHasNoFormErrors();

        $provider->refresh();

        $this->assertSame('Renamed provider', $provider->name);
        $this->assertSame('sk-keepthisexactkey0000000000000000', $provider->api_key);
    }

    /* ------------------------------------------------------------------ *
     * Settings
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_setting_written_here_is_visible_through_the_settings_cache(): void
    {
        $settings = app(Settings::class);

        // Prime the negative cache: this is the entry a direct Eloquent write would strand.
        $this->assertNull($settings->get('panel.written.key'));

        Livewire::test(CreateSetting::class)
            ->fillForm([
                'key' => 'panel.written.key',
                'value' => '"hello"',
                'is_encrypted' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('hello', app(Settings::class)->get('panel.written.key'));
    }

    #[Test]
    public function an_encrypted_setting_keeps_its_value_when_the_editor_is_left_blank(): void
    {
        $settings = app(Settings::class);
        $settings->set('panel.secret.key', 'super-secret-value', true);

        $record = Setting::query()->where('key', 'panel.secret.key')->firstOrFail();

        Livewire::test(EditSetting::class, ['record' => $record->getKey()])
            ->fillForm(['value' => '', 'is_encrypted' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('super-secret-value', app(Settings::class)->get('panel.secret.key'));
        $this->assertTrue((bool) $record->fresh()->is_encrypted);
    }

    #[Test]
    public function the_edit_form_never_receives_an_encrypted_settings_plaintext(): void
    {
        app(Settings::class)->set('panel.secret.key', 'do-not-render-me', true);

        $record = Setting::query()->where('key', 'panel.secret.key')->firstOrFail();

        $this->actingAs($this->admin)
            ->get('/admin/settings/'.$record->getKey().'/edit')
            ->assertOk()
            ->assertDontSee('do-not-render-me', escape: false);
    }

    /* ------------------------------------------------------------------ *
     * Automations
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_scheduled_automation_created_here_is_given_a_next_run(): void
    {
        $workspace = $this->makeWorkspace(['timezone' => 'Europe/London']);
        $member = $this->makeMember($workspace, WorkspaceRole::Owner);

        Livewire::test(CreateAiAutomation::class)
            ->fillForm([
                'name' => 'Weekly status sweep',
                'objective' => 'Summarise every project that has not been updated this week.',
                'mode' => 'copilot',
                'workspace_id' => $workspace->getKey(),
                'created_by' => $member->getKey(),
                'trigger_type' => AutomationTrigger::Schedule->value,
                'schedule_cron' => '0 8 * * 1',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $automation = AiAutomation::withoutWorkspaceScope()->firstOrFail();

        $this->assertNotNull(
            $automation->next_run_at,
            'A scheduled automation with no next_run_at is never selected by AutomationRunner.',
        );
    }

    #[Test]
    public function it_refuses_a_cron_expression_that_can_never_fire(): void
    {
        $workspace = $this->makeWorkspace();
        $member = $this->makeMember($workspace, WorkspaceRole::Owner);

        Livewire::test(CreateAiAutomation::class)
            ->fillForm([
                'name' => 'Broken schedule',
                'objective' => 'Do something.',
                'mode' => 'copilot',
                'workspace_id' => $workspace->getKey(),
                'created_by' => $member->getKey(),
                'trigger_type' => AutomationTrigger::Schedule->value,
                'schedule_cron' => 'every tuesday please',
            ])
            ->call('create')
            ->assertHasFormErrors(['schedule_cron']);
    }

    /* ------------------------------------------------------------------ *
     * Logs stay logs
     * ------------------------------------------------------------------ */

    #[Test]
    public function log_resources_refuse_every_mutation(): void
    {
        $run = AiRun::factory()->create(['workspace_id' => $this->makeWorkspace()->getKey()]);
        $audit = AuditLog::factory()->create();

        $this->assertTrue(AiRunResource::canViewAny());
        $this->assertTrue(AiRunResource::canView($run));
        $this->assertFalse(AiRunResource::canCreate());
        $this->assertFalse(AiRunResource::canEdit($run));
        $this->assertFalse(AiRunResource::canDelete($run));

        $this->assertTrue(AuditLogResource::canViewAny());
        $this->assertFalse(AuditLogResource::canCreate());
        $this->assertFalse(AuditLogResource::canEdit($audit));
        $this->assertFalse(AuditLogResource::canDelete($audit));
    }

    #[Test]
    public function a_non_administrator_is_refused_by_every_resource_guard(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->assertFalse(AiRunResource::canViewAny());
        $this->assertFalse(AuditLogResource::canViewAny());
        $this->assertFalse(WorkspaceResource::canViewAny());
        $this->assertFalse(AiProviderResource::canViewAny());
    }

    /* ------------------------------------------------------------------ *
     * Maintenance mode
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_engages_and_lifts_maintenance_mode(): void
    {
        $key = (string) config('planvio.maintenance.setting_key');
        $messageKey = (string) config('planvio.maintenance.message_key');

        Livewire::test(MaintenanceMode::class)
            ->callAction('engage', ['message' => 'Back at 18:00 UTC.']);

        $this->assertTrue(filter_var(app(Settings::class)->get($key), FILTER_VALIDATE_BOOL));
        $this->assertSame('Back at 18:00 UTC.', app(Settings::class)->get($messageKey));
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.maintenance_engaged']);

        Livewire::test(MaintenanceMode::class)
            ->callAction('lift');

        $this->assertFalse(filter_var(app(Settings::class)->get($key), FILTER_VALIDATE_BOOL));
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.maintenance_lifted']);
    }

    #[Test]
    public function maintenance_mode_keeps_platform_administrators_in_and_everybody_else_out(): void
    {
        $settings = app(Settings::class);
        $settings->set((string) config('planvio.maintenance.setting_key'), true);

        $workspace = $this->makeWorkspace();
        $member = $this->makeMember($workspace, WorkspaceRole::Owner);

        $this->actingAs($member)
            ->get('/w/'.$workspace->slug)
            ->assertStatus(503);

        $this->actingAs($this->admin)
            ->get('/admin/maintenance-mode')
            ->assertOk();
    }
}
