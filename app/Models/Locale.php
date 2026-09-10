<?php

declare(strict_types=1);

namespace App\Models;

use App\Http\Middleware\SetLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * A language this installation offers.
 *
 * This table is the allow-list. `users.locale` and `workspaces.locale` hold a code, but a
 * code alone is not permission to render in that language — {@see SetLocale} accepts a
 * stored value only when a row here carries it and `is_enabled` is true. Disabling a
 * language therefore takes effect immediately, without touching a single user row.
 *
 * `direction` is data rather than a derived property. Deriving it from a hard-coded list of
 * RTL codes is wrong the first time somebody adds a language the list has never heard of,
 * and the layout asks for the answer on every request.
 */
final class Locale extends Model
{
    public const LTR = 'ltr';

    public const RTL = 'rtl';

    /**
     * Last-resort direction lookup for when the locales table cannot be read — a queued
     * mail render, or any code path that runs before installation. The stored row always
     * wins; this only decides what an unknown code looks like.
     *
     * @var list<string>
     */
    private const RTL_LANGUAGES = ['ar', 'he', 'fa', 'ur', 'ps', 'sd', 'ug', 'yi', 'dv', 'ku', 'ckb'];

    /**
     * The languages this release ships a catalogue for.
     *
     * Rows in `locales` are the authority everywhere else, and this list is what
     * `Database\Seeders\DefaultDataSeeder` writes them from. It lives on the model rather
     * than in the seeder because the installer needs the same list *before* the table
     * exists: the wizard renders on a server with no database at all, and a language picker
     * cannot ask a table that has not been created yet which languages there are.
     *
     * English is first because it is the language the product is written in. Arabic ships
     * beside it because right-to-left is not something that can be added later without
     * being designed for: a stylesheet written with `left` and `margin-left` is wrong in
     * every RTL language, and the only way to know it is not is to have one installed.
     *
     * @var list<array{code: string, name: string, native_name: string, direction: string, is_default: bool, position: int}>
     */
    public const SHIPPED = [
        [
            'code' => 'en',
            'name' => 'English',
            'native_name' => 'English',
            'direction' => self::LTR,
            'is_default' => true,
            'position' => 0,
        ],
        [
            'code' => 'ar',
            'name' => 'Arabic',
            'native_name' => 'العربية',
            'direction' => self::RTL,
            'is_default' => false,
            'position' => 1,
        ],
    ];

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'native_name',
        'direction',
        'is_enabled',
        'is_default',
        'position',
        'date_format',
        'first_day_of_week',
    ];

    /**
     * Every enabled language, keyed by code, in the order the pickers list them.
     *
     * Returns an empty collection rather than throwing when the table is absent: the
     * installer renders localised screens before migrations have run, and a language picker
     * with nothing in it is a recoverable state where a fatal error is not.
     *
     * @return Collection<string, self>
     */
    public static function enabledByCode(): Collection
    {
        try {
            return self::query()->enabled()->ordered()->get()->keyBy('code');
        } catch (QueryException) {
            /** @var Collection<string, self> */
            return new Collection;
        }
    }

    /**
     * The languages the wizard can be read in, keyed by code.
     *
     * Unsaved instances of {@see self::SHIPPED}: this is asked before `migrate` has run, so
     * there is nothing to read and nothing to write. They carry a code, a direction and an
     * endonym, which is everything a picker and {@see SetLocale} need.
     *
     * @return Collection<string, self>
     */
    public static function shipped(): Collection
    {
        /** @var Collection<string, self> */
        return (new Collection(self::SHIPPED))->mapWithKeys(
            static fn (array $row): array => [$row['code'] => (new self)->forceFill($row)],
        );
    }

    /**
     * The installation-wide fallback: the row flagged default, or the first enabled one.
     */
    public static function fallback(): ?self
    {
        try {
            return self::query()
                ->enabled()
                ->orderByDesc('is_default')
                ->ordered()
                ->first();
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_enabled'), true);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($this->qualifyColumn('position'))
            ->orderBy($this->qualifyColumn('name'));
    }

    public function isRtl(): bool
    {
        return $this->direction === self::RTL;
    }

    /**
     * The text direction for a locale code, resolvable without a request.
     *
     * A queued notification renders in a worker: there is no middleware, nothing shared
     * with the view, and possibly no locales table yet. So this reads the row when it can
     * and falls back to a small list of the scripts that are written right to left —
     * which is enough for an email shell to pick a side and is never worse than assuming
     * left to right.
     */
    public static function directionFor(?string $code): string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return self::LTR;
        }

        try {
            $stored = self::query()->where('code', $code)->value('direction');

            if (is_string($stored) && $stored !== '') {
                return $stored === self::RTL ? self::RTL : self::LTR;
            }
        } catch (Throwable) {
            // No database, or no locales table: fall through to the static list.
        }

        $language = strtolower(explode('-', str_replace('_', '-', $code))[0]);

        return in_array($language, self::RTL_LANGUAGES, true) ? self::RTL : self::LTR;
    }

    /**
     * What a picker shows: the endonym, with the English name beside it when they differ.
     */
    public function label(): string
    {
        $native = (string) $this->native_name;
        $name = (string) $this->name;

        if ($native === '' || $native === $name) {
            return $name === '' ? (string) $this->code : $name;
        }

        return $native.' — '.$name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'position' => 'integer',
            'first_day_of_week' => 'integer',
        ];
    }
}
