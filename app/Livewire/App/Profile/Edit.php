<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile;

use App\Http\Middleware\SetLocale;
use App\Models\AuditLog;
use App\Models\Locale;
use App\Models\User;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The account, as opposed to the workspace: who you are on this installation.
 *
 * These values follow the person across every workspace they belong to, which is why they
 * live here and not in workspace settings. The timezone in particular is not decoration —
 * it is what every date on every screen is rendered in.
 */
#[Layout('layouts.app')]
final class Edit extends Component
{
    use Concerns\NeedsAWorkspace;
    use WithFileUploads;

    public string $name = '';

    public string $email = '';

    public string $jobTitle = '';

    public string $timezone = 'UTC';

    public string $locale = 'en';

    public string $theme = 'system';

    /** @var TemporaryUploadedFile|null */
    public $avatar = null;

    public function mount(): void
    {
        $this->ensureWorkspace();

        $user = $this->actor();

        $this->name = (string) $user->name;
        $this->email = (string) $user->email;
        $this->jobTitle = (string) $user->job_title;
        $this->timezone = (string) ($user->timezone ?: 'UTC');
        $this->locale = $this->offeredLocale((string) $user->locale);
        $this->theme = (string) ($user->theme ?: 'system');
    }

    /**
     * @return list<string>
     */
    public function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    /**
     * The languages this installation offers, as `code => label`.
     *
     * Read from `locales`, not from what happens to be sitting in `lang/`: the table is what
     * {@see SetLocale} will accept when the page renders, and offering a
     * language that middleware is going to ignore would be a preference that silently does
     * nothing.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function locales(): array
    {
        $available = Locale::enabledByCode()
            ->mapWithKeys(static fn (Locale $locale): array => [(string) $locale->code => $locale->label()])
            ->all();

        // Before the catalogue is seeded there is still a form to render, and English is
        // still what it renders in.
        return $available === []
            ? [(string) config('app.locale', 'en') => 'English']
            : $available;
    }

    /**
     * The stored code, or the installation's own language when that one is no longer offered.
     *
     * A picker showing a language nobody can be given is worse than one that shows what is
     * actually in force: the page already renders in the fallback, so the field should say so.
     */
    private function offeredLocale(string $stored): string
    {
        $offered = $this->locales;

        if ($stored !== '' && array_key_exists($stored, $offered)) {
            return $stored;
        }

        $default = Locale::fallback()?->code;

        return (string) ($default ?? array_key_first($offered) ?? config('app.locale', 'en'));
    }

    public function avatarUrl(): string
    {
        return $this->actor()->avatarUrl();
    }

    public function save(): void
    {
        $user = $this->actor();

        $data = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->getKey())],
            'jobTitle' => ['nullable', 'string', 'max:120'],
            'timezone' => ['required', 'string', Rule::in($this->timezones())],
            'locale' => ['required', 'string', Rule::in(array_keys($this->locales))],
            'theme' => ['required', Rule::in(['light', 'dark', 'system'])],
        ], attributes: ['jobTitle' => __('job title')]);

        $emailChanged = mb_strtolower($data['email']) !== mb_strtolower((string) $user->email);
        $previousEmail = (string) $user->email;

        $this->storeAvatar($user);

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->job_title = $data['jobTitle'] === '' ? null : $data['jobTitle'];
        $user->timezone = $data['timezone'];
        $user->locale = $data['locale'];
        $user->theme = $data['theme'];

        if ($emailChanged) {
            // The new address has not been proved yet. Verification may be switched off on
            // this installation, but the column is the record of whether it was ever done,
            // and it must not keep vouching for an address nobody confirmed.
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            // A sign-in address change is a security event, not a preference: it belongs in
            // the audit trail beside the sign-ins themselves. The address is recorded, never
            // anything that could be used to sign in with it.
            AuditLog::query()->create([
                'user_id' => $user->getKey(),
                'event' => 'account.email_changed',
                'description' => __('Sign-in address changed.'),
                'ip' => request()->ip(),
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
                'properties' => ['from' => $previousEmail, 'to' => (string) $user->email],
            ]);
        }

        $this->avatar = null;

        $this->dispatch('planvio-notify', type: 'success', message: __('Profile saved.'));

        // The theme is applied by a script in <head> from localStorage, so the browser has
        // to be told: the column is the durable record, the local value is what paints.
        $this->dispatch('planvio-theme-preference', theme: $data['theme']);
    }

    public function removeAvatar(): void
    {
        $user = $this->actor();
        $path = $user->avatar_path;

        $user->avatar_path = null;
        $user->save();

        if (is_string($path) && $path !== '') {
            Storage::disk('public')->delete($path);
        }

        $this->dispatch('planvio-notify', type: 'success', message: __('Photo removed.'));
    }

    public function render(): View
    {
        return view('livewire.app.profile.edit')->title(__('Profile'));
    }

    private function storeAvatar(User $user): void
    {
        if (! $this->avatar instanceof TemporaryUploadedFile) {
            return;
        }

        // Raster only: an avatar is served from the public disk, and an SVG there is a
        // document that could run script in the product's own origin.
        $this->validate([
            'avatar' => [
                'mimes:png,jpg,jpeg,webp',
                'max:'.(int) config('planvio.uploads.avatar_max_size_kb', 2048),
            ],
        ], attributes: ['avatar' => __('photo')]);

        $previous = $user->avatar_path;
        $path = $this->avatar->store('avatars', 'public');

        if (! is_string($path)) {
            return;
        }

        $user->avatar_path = $path;

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
