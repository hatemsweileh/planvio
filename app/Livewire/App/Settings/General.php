<?php

declare(strict_types=1);

namespace App\Livewire\App\Settings;

use App\Actions\Workspaces\UpdateWorkspace;
use App\Actions\Workspaces\WorkspaceAttributes;
use App\Exceptions\DomainException;
use App\Http\Middleware\SetLocale;
use App\Models\Locale;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Formats;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Identity and formats: what the workspace is called, what colour it is, and how it writes
 * dates and money.
 *
 * The accent is applied live. The shell re-declares `--accent` from `workspaces.accent_color`
 * on every page, and every component in the design system reads that token rather than a
 * brand step — so saving a colour here re-skins the product on the next paint, with no
 * rebuild and no per-component override.
 *
 * The logo is the one upload in Planvio that is deliberately public: it is drawn in the
 * workspace switcher on every page, and streaming it through the authorising controller
 * would put a database round trip in front of a 4 KB image. Attachments never go here
 * (ARCHITECTURE.md §9).
 */
final class General extends Component
{
    use WithFileUploads;

    /** Swatches, not a palette lock — the picker accepts any colour. */
    private const PRESETS = [
        '#3F66B0', '#2F6F5E', '#8A5CD6', '#B4532F', '#C2185B', '#0E7490', '#4B5563', '#B45309',
    ];

    public Workspace $workspace;

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    public string $accentColor = '#3F66B0';

    public string $timezone = 'UTC';

    public string $locale = 'en';

    public string $currency = 'USD';

    public string $dateFormat = 'Y-m-d';

    public int $weekStartsOn = 1;

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    public function mount(Workspace $workspace): void
    {
        $this->authorize('update', $workspace);

        $this->workspace = $workspace;

        $this->name = (string) $workspace->name;
        $this->slug = (string) $workspace->slug;
        $this->description = (string) $workspace->description;
        $this->accentColor = (string) ($workspace->accent_color ?: '#3F66B0');
        $this->timezone = (string) ($workspace->timezone ?: 'UTC');
        $this->locale = $this->offeredLocale((string) $workspace->locale);
        $this->currency = (string) ($workspace->currency ?: 'USD');
        $this->dateFormat = (string) ($workspace->date_format ?: 'Y-m-d');
        $this->weekStartsOn = (int) $workspace->week_starts_on;
    }

    /**
     * @return list<string>
     */
    public function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    /**
     * @return list<string>
     */
    public function presets(): array
    {
        return self::PRESETS;
    }

    /**
     * The languages this installation offers, as `code => label`.
     *
     * Read from `locales` rather than from what happens to be sitting in `lang/`. The table
     * is the allow-list {@see SetLocale} enforces when a page renders,
     * so a language an administrator switched off must not still be selectable here —
     * choosing it would store a preference that quietly does nothing.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function locales(): array
    {
        $available = Locale::enabledByCode()
            ->mapWithKeys(static fn (Locale $locale): array => [(string) $locale->code => $locale->label()])
            ->all();

        // Before the catalogue is seeded there is still a form to render.
        return $available === []
            ? [(string) config('app.locale', 'en') => 'English']
            : $available;
    }

    /**
     * The stored code, or the installation's default when that language is no longer offered.
     *
     * The page already renders in the fallback, because the middleware refuses a code the
     * `locales` table does not carry. Leaving the stale value in the picker would claim
     * otherwise, and the first save would fail validation on a value the form itself put there.
     */
    private function offeredLocale(string $stored): string
    {
        $offered = $this->locales;

        if ($stored !== '' && array_key_exists($stored, $offered)) {
            return $stored;
        }

        return (string) (Locale::fallback()?->code ?? array_key_first($offered) ?? config('app.locale', 'en'));
    }

    /**
     * Date formats, each rendered with today's date so the choice is legible.
     *
     * `translatedFormat`, not `format`: the second is PHP's own and names September in
     * English however the page is rendered, so an Arabic reader would be shown a preview of
     * `j M Y` that does not look like what choosing it produces.
     *
     * @return array<string, string>
     */
    public function dateFormats(): array
    {
        $now = now();
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'j M Y', 'M j, Y', 'D, j M Y'];

        $options = [];

        foreach ($formats as $format) {
            $options[$format] = Formats::using($now, $format);
        }

        return $options;
    }

    /**
     * The seven weekday names, keyed by the value `workspaces.week_starts_on` stores
     * (0 = Sunday … 6 = Saturday).
     *
     * The anchor is named rather than left to `startOfWeek()`, which resolves the first day
     * of the week from the *locale* — Saturday in Arabic, Monday in English. With the offset
     * below computed against Monday, an unanchored start slid every label two days along, so
     * an Arabic reader picking "Monday" was choosing a row labelled السبت.
     *
     * @return array<int, string>
     */
    public function weekdays(): array
    {
        $monday = now()->startOfWeek(CarbonInterface::MONDAY);

        $days = [];

        foreach ([1, 2, 3, 4, 5, 6, 0] as $day) {
            $days[$day] = $monday->copy()->addDays(($day + 6) % 7)->translatedFormat('l');
        }

        return $days;
    }

    public function logoUrl(): ?string
    {
        $path = $this->workspace->logo_path;

        return is_string($path) && $path !== ''
            ? Storage::disk('public')->url($path)
            : null;
    }

    public function save(UpdateWorkspace $updateWorkspace): void
    {
        $this->authorize('update', $this->workspace);

        $data = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'slug' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'accentColor' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'timezone' => ['required', 'string', Rule::in($this->timezones())],
            'locale' => ['required', 'string', Rule::in(array_keys($this->locales))],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'dateFormat' => ['required', 'string', Rule::in(array_keys($this->dateFormats()))],
            'weekStartsOn' => ['required', 'integer', 'between:0,6'],
        ], attributes: [
            'accentColor' => __('accent colour'),
            'dateFormat' => __('date format'),
            'weekStartsOn' => __('first day of the week'),
        ]);

        $logoPath = $this->storeLogo();

        // Captured before the write: the Action mutates and returns the same instance, so
        // afterwards there is nothing left to compare the new slug against.
        $previousSlug = (string) $this->workspace->slug;

        try {
            $workspace = $updateWorkspace(
                workspace: $this->workspace,
                attributes: new WorkspaceAttributes(
                    name: $data['name'],
                    slug: $data['slug'],
                    description: $data['description'] ?? '',
                    logoPath: $logoPath,
                    accentColor: mb_strtolower($data['accentColor']),
                    timezone: $data['timezone'],
                    locale: $data['locale'],
                    currency: $data['currency'],
                    dateFormat: $data['dateFormat'],
                    weekStartsOn: $data['weekStartsOn'],
                ),
                actor: $this->actor(),
            );
        } catch (DomainException $failure) {
            $this->addError('name', $failure->userMessage());

            return;
        }

        $slugChanged = (string) $workspace->slug !== $previousSlug;

        $this->workspace = $workspace;
        $this->slug = (string) $workspace->slug;
        $this->logo = null;

        $this->dispatch('planvio-notify', type: 'success', message: __('Workspace settings saved.'));

        // Every URL in the product carries the slug, so the address bar has to follow it
        // or the next click 404s on a workspace that no longer answers to that name.
        if ($slugChanged) {
            $this->redirect(route('app.settings', $workspace).'?section=general');

            return;
        }

        // The accent is a CSS variable the layout writes once, in <head>, on a full page
        // load. Announcing the new value lets the page set the token itself, so the whole
        // product re-skins in the same frame as the save rather than on the next reload.
        $this->dispatch('planvio-accent-changed', color: mb_strtolower($data['accentColor']));
    }

    public function removeLogo(UpdateWorkspace $updateWorkspace): void
    {
        $this->authorize('update', $this->workspace);

        $existing = $this->workspace->logo_path;

        $workspace = $updateWorkspace(
            workspace: $this->workspace,
            attributes: new WorkspaceAttributes(logoPath: ''),
            actor: $this->actor(),
        );

        if (is_string($existing) && $existing !== '') {
            Storage::disk('public')->delete($existing);
        }

        $this->workspace = $workspace;

        $this->dispatch('planvio-notify', type: 'success', message: __('Logo removed.'));
    }

    public function render(): View
    {
        return view('livewire.app.settings.general');
    }

    /**
     * Put the uploaded logo on the public disk and return its path, or null when nothing
     * was uploaded — which the attribute carrier reads as "leave it alone".
     */
    private function storeLogo(): ?string
    {
        if (! $this->logo instanceof TemporaryUploadedFile) {
            return null;
        }

        // Raster only, deliberately. This file is served straight off the public disk, and
        // an SVG is a document that can carry script — one uploaded here would run in the
        // product's own origin. Attachments get the SVG sanitiser; this path gets a
        // narrower allow-list instead.
        $this->validate([
            'logo' => [
                'mimes:png,jpg,jpeg,webp',
                'max:'.(int) config('planvio.uploads.avatar_max_size_kb', 2048),
            ],
        ], attributes: ['logo' => __('logo')]);

        $previous = $this->workspace->logo_path;

        $path = $this->logo->store('workspace-logos', 'public');

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return is_string($path) ? $path : null;
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
