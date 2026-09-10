# Translations

Three places a string can live, and they are not interchangeable.

## `lang/en/*.php` — keyed catalogues

`__('enums.priority.high')`, `__('search.types.task')`. Use these for anything structured:
enum labels, search group names, action descriptions.

**A key built at runtime must live here.** `__('enums.priority.'.$case->value)` cannot be
recovered by reading the source, so no scanner will ever find it. What makes those keys
translatable is that the file itself is read: `lang:scan` catalogues every line in every
`lang/en/*.php` group, whether or not anything in the source names it literally. A dynamic
key with no group file behind it is a string no translator will ever be offered.

Laravel's own `auth`, `passwords`, `pagination` and `validation` groups are catalogued the
same way, from the framework's files.

## `lang/en.json` — literal strings

The rest of the product calls `__()` with the English string itself: `__('Collapse sidebar')`.
Laravel returns an unknown key verbatim, so English renders correctly whether or not the
string is listed here — which is exactly why the file has to be generated rather than kept by
hand. Nobody notices a missing entry until a second language renders English.

**`lang/en.json` is generated. Do not edit it by hand:**

```bash
php artisan lang:scan          # rebuild it from every literal __() in app/ and resources/
php artisan lang:scan --dry-run
```

Every key maps to itself, so English is unchanged by definition. Two things survive a rescan:
an entry whose value differs from its key (an installation that reworded its own English), and
everything at all under `--keep-orphans`.

### The one-word trap this closes

A single-word key with no dot is parsed by Laravel as a *group name*, so `__('Search')` looks
for `lang/en/Search.php` and, if it finds one, returns the whole file as an array — which then
blows up the moment Blade tries to escape it. On Windows and macOS the filesystem is
case-insensitive, so `__('AI')` finds `lang/en/ai.php`.

JSON translations are consulted first, so listing the word in `en.json` fixes it. `lang:scan`
lists every literal key, so every such collision is covered automatically — including in other
languages, because `DatabaseTranslationLoader` fills a locale's JSON catalogue from English
before Laravel gets the chance to re-parse a missing key as a group name.

## The `translations` table — an installation's own edits

Rows override both of the above, per locale, and survive an upgrade in a way that editing a
file inside the release ZIP does not. `App\Services\Translation\DatabaseTranslationLoader`
merges them over the file at load time; a row with a null value means "known, not translated
yet" and changes nothing.

```bash
php artisan lang:sync                      # give every enabled locale a row per key
php artisan lang:missing ar                # what Arabic still cannot say
php artisan lang:export ar --output=ar.json
php artisan lang:import ar ar.json
```

Which languages exist at all is the `locales` table, seeded by `DefaultDataSeeder`. A code in
`users.locale` or `workspaces.locale` is only honoured when a row there carries it and is
enabled — see `App\Http\Middleware\SetLocale`.

## After you add a string

Run `php artisan lang:scan`, then **translate the new key into every shipped language**.
`tests/Feature/Localisation/TranslationIntegrityTest.php` fails the build if you do not, and
that is the point: `__()` returns an unknown key verbatim, so a key nobody translated renders
English inside an Arabic page with no exception, no log line and nothing on screen to notice.
The same test refuses a translation that dropped a `:placeholder` the English needs, because
Laravel substitutes what it was handed and leaves the rest of the line alone — the value
simply disappears from the sentence.
