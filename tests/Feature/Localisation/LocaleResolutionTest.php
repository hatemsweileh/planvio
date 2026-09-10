<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Enums\WorkspaceRole;
use App\Http\Middleware\SetLocale;
use App\Models\Locale;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Translation\TranslationRepository;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * What language a request renders in, and why.
 *
 * The order is the contract: the person, then the workspace, then the installation default,
 * then `config('app.locale')`. The refusals matter more than the order — a code that names a
 * language this installation does not offer must be skipped rather than accepted, because
 * `users.locale` and `workspaces.locale` are plain columns that keep whatever was written to
 * them long after a language was switched off.
 */
final class LocaleResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLocales();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme', 'locale' => 'en']);
        $this->user = $this->makeMember($this->workspace, WorkspaceRole::Owner, ['locale' => 'en']);
    }

    #[Test]
    public function the_users_own_locale_wins_over_the_workspace(): void
    {
        $this->user->forceFill(['locale' => 'ar'])->save();
        $this->workspace->forceFill(['locale' => 'fr'])->save();

        $this->assertSame('ar', $this->resolveFor($this->user, $this->workspace));
    }

    #[Test]
    public function the_workspace_locale_applies_when_the_user_has_none(): void
    {
        $this->user->forceFill(['locale' => ''])->save();
        $this->workspace->forceFill(['locale' => 'ar'])->save();

        $this->assertSame('ar', $this->resolveFor($this->user, $this->workspace));
    }

    #[Test]
    public function the_default_locale_row_applies_when_neither_names_one(): void
    {
        $this->user->forceFill(['locale' => ''])->save();
        $this->workspace->forceFill(['locale' => ''])->save();

        Locale::query()->where('code', 'en')->update(['is_default' => false]);
        Locale::query()->where('code', 'ar')->update(['is_default' => true]);

        $this->assertSame('ar', $this->resolveFor($this->user, $this->workspace));
    }

    #[Test]
    public function a_locale_that_does_not_exist_is_never_accepted(): void
    {
        $this->user->forceFill(['locale' => 'de'])->save();
        $this->workspace->forceFill(['locale' => 'ar'])->save();

        // Not `de`: the column says so, but the installation does not offer it.
        $this->assertSame('ar', $this->resolveFor($this->user, $this->workspace));
    }

    #[Test]
    public function a_disabled_locale_is_never_accepted(): void
    {
        Locale::query()->where('code', 'ar')->update(['is_enabled' => false]);

        $this->user->forceFill(['locale' => 'ar'])->save();
        $this->workspace->forceFill(['locale' => 'ar'])->save();

        $this->assertSame('en', $this->resolveFor($this->user, $this->workspace));
    }

    #[Test]
    public function the_configured_locale_is_the_last_resort(): void
    {
        Locale::query()->delete();

        config(['app.locale' => 'en']);

        $this->user->forceFill(['locale' => 'ar'])->save();

        $this->assertSame('en', $this->resolveFor($this->user, $this->workspace));
    }

    #[Test]
    public function the_direction_is_shared_with_views(): void
    {
        $this->user->forceFill(['locale' => 'ar'])->save();

        $this->resolveFor($this->user, $this->workspace);

        $this->assertSame(Locale::RTL, View::shared('textDirection'));

        $this->user->forceFill(['locale' => 'en'])->save();

        $this->resolveFor($this->user, $this->workspace);

        $this->assertSame(Locale::LTR, View::shared('textDirection'));
    }

    #[Test]
    public function the_direction_is_shared_even_when_no_locale_is_stored(): void
    {
        Locale::query()->delete();

        $this->resolveFor($this->user, null);

        $this->assertSame(Locale::LTR, View::shared('textDirection'));
    }

    #[Test]
    public function a_signed_out_request_falls_back_to_the_default_row(): void
    {
        Locale::query()->where('code', 'en')->update(['is_default' => false]);
        Locale::query()->where('code', 'ar')->update(['is_default' => true]);

        $this->assertSame('ar', $this->resolveFor(null, null));
    }

    #[Test]
    public function the_workspace_in_the_url_is_only_read_for_a_member(): void
    {
        $foreign = $this->makeWorkspace(['slug' => 'northwind', 'locale' => 'ar']);

        $this->user->forceFill(['locale' => ''])->save();

        // Resolved through the route parameter rather than a bound tenant, which is the state
        // this middleware is in when it runs ahead of SetCurrentWorkspace in the web group.
        $this->assertSame('en', $this->resolveThroughRoute($this->user, $foreign->slug));

        $this->workspace->forceFill(['locale' => 'ar'])->save();

        $this->assertSame('ar', $this->resolveThroughRoute($this->user, $this->workspace->slug));
    }

    #[Test]
    public function the_middleware_is_registered_in_the_web_group_and_kept_across_livewire_updates(): void
    {
        $this->assertContains(
            SetLocale::class,
            app('router')->getMiddlewareGroups()['web'],
            'SetLocale must run on every web request, not only on routes that remember to ask for it.',
        );

        $this->assertContains(
            SetLocale::class,
            Livewire::getPersistentMiddleware(),
            'A Livewire update that loses the locale answers an Arabic page in English.',
        );
    }

    #[Test]
    public function a_page_renders_in_the_language_the_user_chose(): void
    {
        $this->user->forceFill(['locale' => 'ar'])->save();

        app(TranslationRepository::class)
            ->put('ar', null, 'My Tasks', 'مهامي');

        $response = $this->actingAs($this->user)->get('/w/acme/my-tasks');

        $response->assertOk();
        $response->assertSee('مهامي', escape: false);
    }

    /* ------------------------------------------------------------------ *
     * Harness
     * ------------------------------------------------------------------ */

    private function seedLocales(): void
    {
        Locale::query()->create([
            'code' => 'en', 'name' => 'English', 'native_name' => 'English',
            'direction' => Locale::LTR, 'is_enabled' => true, 'is_default' => true, 'position' => 0,
        ]);

        Locale::query()->create([
            'code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية',
            'direction' => Locale::RTL, 'is_enabled' => true, 'is_default' => false, 'position' => 1,
        ]);
    }

    /**
     * Run the middleware with the tenant already bound, the way it sees a Livewire update.
     */
    private function resolveFor(?User $user, ?Workspace $workspace): string
    {
        $current = app(CurrentWorkspace::class);
        $current->forget();

        if ($workspace instanceof Workspace) {
            $current->set($workspace);
        }

        $request = Request::create('/');

        $request->setUserResolver(static fn (): ?User => $user);

        app(SetLocale::class)->handle($request, static fn (): Response => new Response);

        return app()->getLocale();
    }

    /**
     * Run it with nothing bound and only a `{workspace}` route parameter to go on.
     */
    private function resolveThroughRoute(User $user, string $slug): string
    {
        app(CurrentWorkspace::class)->forget();

        $route = new Route(['GET'], '/w/{workspace}', static fn (): string => '');
        $route->bind(Request::create('/w/'.$slug));

        $request = Request::create('/w/'.$slug);
        $request->setUserResolver(static fn (): User => $user);
        $request->setRouteResolver(static fn () => $route);

        app(SetLocale::class)->handle($request, static fn (): Response => new Response);

        return app()->getLocale();
    }
}
