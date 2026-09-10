# Localisation

Planvio ships in English and can be translated into any language without touching a file
inside the release. This page covers how the language of a page is decided, how to add a
language, how the translation editor works, the one rule that will break a translated
install if you ignore it, how the AI first pass works and why its output starts unreviewed,
how to send work to an outside translator and take it back, and what right-to-left support
does and does not cover.

Everything described here is reached from **Admin → Platform → Languages** and
**Admin → Platform → Translations**, and every one of those screens has an equivalent
`php artisan lang:*` command for people who prefer a terminal.

---

## 1. How a language is decided

`locales` is the list of languages this installation offers. `users.locale` and
`workspaces.locale` are ordinary columns that hold a code — and a code is not permission to
render in that language. `App\Http\Middleware\SetLocale` accepts a stored value **only when a
row in `locales` carries it and `is_enabled` is true**, which is what makes switching a
language off take effect on the next request rather than after somebody clears every user
row.

The order, most specific first:

1. the signed-in person's own `users.locale`;
2. the workspace they are in (`workspaces.locale`) — a shared space has a house language, and
   somebody who never chose one should get it;
3. the `locales` row flagged default;
4. `config('app.locale')`, which is whatever the installer wrote.

Each step is **skipped** when it names a language this installation does not offer, rather
than accepted and then failed on.

Nothing reads a locale out of the request. There is no `?lang=` and no `Accept-Language`,
deliberately: a language is a stored preference, and honouring a query parameter would let one
link change what a subsequent form submission says it agreed to.

`SetLocale` also shares the resolved direction with every view as `$textDirection`, which is
what `resources/views/layouts/app.blade.php` puts in `dir=`.

### Where the text itself comes from

Three layers, consulted in this order (`App\Services\Translation\DatabaseTranslationLoader`):

| Layer | What it is | Survives an upgrade |
|---|---|---|
| `translations` table | This installation's own wording, per language | **Yes** |
| `lang/<locale>.json` and `lang/<locale>/*.php` | What the release ships | No — replaced by the next ZIP |
| the key itself | `__()` returns an unknown key verbatim | — |

That third row is why English renders correctly whether or not anybody wrote it down, and it
is exactly why a second language is work: a key nobody catalogued renders English.

Rows beat files. So the way to say "client" where Planvio says "customer" is to edit English
in the translation editor — that is a row, and it is still there after you upgrade.

---

## 2. Adding a language

**Admin → Platform → Languages → Add language.**

| Field | What it is for |
|---|---|
| **Code** | BCP-47: `ar`, `de`, `pt-BR`, `sr-Latn-RS`. Normalised on save, so `pt-br` and `PT-BR` cannot become two rows. This is the identity of every translation row and every `users.locale` value, so it cannot be changed afterwards |
| **Direction** | `ltr` or `rtl`. Stored rather than guessed from the code: a hard-coded list of RTL languages is wrong the first time somebody adds one that is not on it |
| **Name / Native name** | The English name and the endonym. A picker shows the endonym first, because the person choosing it may read nothing else |
| **Offered to users** | Whether it appears in language pickers at all |
| **Installation default** | Where every fallback lands |
| **Start the translator off with** | See below |
| **Date format / First day of week** | Optional. Left blank, the workspace's own settings apply |

### Seeding

A brand-new language has no rows, so the translation editor would open on an empty list —
which reads as a broken screen rather than as a new language. The seed choice fixes that:

- **Every key, untranslated** (the default) writes one row per key with a null value. That is
  the worklist a translator works down, and it changes nothing that renders: a null value is
  exactly what the loader skips.
- **Every key, plus the wording already stored for *X*** does the same and then copies another
  language's lines in as a starting point. This is what makes `pt-BR` a morning rather than a
  week if you already have `pt`. Copied lines arrive **unreviewed**: they are a draft in the
  new language, not an approval of it.
- **Nothing** leaves the language empty, for when you are about to import a file.

The same thing is available later as **Sync catalogue**, on the language row and in the header
of the editor. It is safe to re-run: existing rows are never touched.

From a terminal: `php artisan lang:sync`.

### The two guard rails

**The default language cannot be switched off or deleted.** Locale resolution ends at the
default row; without one, an installation whose users all named a language that was later
removed falls through to `config('app.locale')`. To change the default, use **Make default**
on another language — the flag moves in one operation, so there is never a moment with none.

**Switching a language off moves the people on it.** The middleware already degrades — a code
this installation no longer offers is skipped, and the person sees the default — so nothing
breaks if the columns are left alone. What breaks is later: re-enabling a half-finished
language would silently throw all those accounts back into it without anybody choosing that.
So `users.locale` and `workspaces.locale` are rewritten to the default at the moment the
language is switched off, and the confirmation tells you how many rows that is first.

Deleting a language deletes its stored lines with it. Export first if there is any chance you
want them back.

---

## 3. The translation editor

**Admin → Platform → Translations.**

Pick a language, pick a catalogue, and work down the list. Each row shows the English, the
translation as an editable field, the placeholders the English needs, and a reviewed
checkbox.

### The two catalogues, and why they are kept apart

Laravel has two, and confusing them is the classic way to break a translated app:

- **Literal strings** (`*`) is `lang/<locale>.json`, addressed by the English sentence itself:
  `__('Create project')`. Nearly every string in Planvio lives here.
- **Groups** (`actions`, `ai`, `enums`, `search`, plus the framework's `auth`, `passwords`,
  `pagination`, `validation`) are `lang/<locale>/<group>.php`, addressed by a dotted path:
  `__('enums.priority.high')`.

A group key written into the JSON catalogue *shadows the file it was addressing* and puts the
raw key on screen. The catalogue selector on this screen is that distinction made visible, and
every read and write carries the catalogue with it, so the editor cannot make that mistake.

Keys that are assembled at runtime — `__('enums.priority.'.$case->value)` — can only live in a
group file, because no scanner can find them in the source. See `lang/README.md`.

### The filters

| Filter | Shows |
|---|---|
| **Untranslated** | Nothing renders in this language yet |
| **Needs review** | Translated, but nobody has approved it — where AI output and imports land |
| **English changed since** | The English was edited after the translation was written, so the translation is answering an older question |
| **Search** | Substring of the English, the translation or the key |

"English changed since" compares the write time of the English row against the write time of
the translation. It therefore catches the case that actually happens on a running install:
somebody rewords the product's own English in this editor and every translation of that line
becomes subtly wrong. It does **not** catch an English string reworded in the *source code*
between releases, because for the literal catalogue a reworded English sentence is a different
key — the old translation becomes an orphan instead. The count of those is shown beside the
filters, and `php artisan lang:sync --prune` clears the untranslated ones (it never deletes a
line somebody translated).

### Saving

**Save this page** writes the lines that changed. Clearing a field stores nothing rather than
an empty string, so the line falls back to the shipped English — an empty string would blank
the text instead.

A save is live on the next request, in every process, with no artisan command. Writing a
translation bumps a version stamp that every cache key in the loader carries, and clears the
translator's own copy of what it had already loaded.

---

## 4. The placeholder rule

**This is the one rule that will break a translated install, and it is enforced rather than
suggested.**

`__('Due in :count days', ['count' => 3])` renders "Due in 3 days". A translation that reads
perfectly and has dropped `:count` renders a sentence with the number silently gone. Laravel
substitutes the placeholders it was given and leaves the rest of the string alone, so there is
**no run-time signal at all** — no exception, no log line, nothing in the interface. The only
moment the mistake is visible is the moment it is written down.

So:

- **A save containing one is refused entirely.** Not partially applied with a warning: the
  whole page is rejected and the message names the line and the missing tokens. A partial save
  would leave you unable to tell which of your forty edits had landed.
- **An import leaves those lines out**, counts them, and lists them in the preview before you
  apply anything.
- **An AI answer that lost one is discarded** and never reaches the table, so the key stays
  listed as untranslated — a visible, fixable state rather than a finished-looking wrong one.

A placeholder is a colon followed by a letter or underscore and then word characters, where
the colon is not itself preceded by a word character or another colon. Clock times (`12:30`),
URLs (`https://…`) and `Model::make` are therefore not placeholders. Names are compared
case-insensitively, because Laravel substitutes `:count`, `:Count` and `:COUNT` alike — a
translator who capitalised one to start a sentence has still kept it.

Pluralised lines carry their structure too: `{1} 1 task|[2,*] :count tasks`. Keep the `|`
separators and the `{0}`, `{1}`, `[2,*]` markers and translate the text after them. Planvio
does not force a segment count, because languages do not agree on how many plural forms they
have — Arabic has six.

---

## 5. AI-assisted translation

Three and a half thousand interface strings is a week of somebody's life before a single one
has been read. **Translate untranslated strings** shortens the first draft.

**It is offered only when the AI layer is configured and switched on.** If it is off there is
no button — not a disabled one — and the reason is shown on the page instead. The gate is the
platform half of `App\Ai\AiGate`, asked of the global `ai_settings` row (the one a workspace
inherits when it has none of its own), because translating the interface is a platform act
with no tenant:

1. `AI_ENABLED` in `.env`;
2. a global `ai_settings` row exists and `is_enabled` is true;
3. its kill switch is not engaged;
4. it points at an active provider — or, if it names none, an active default provider exists.

### What it does

`App\Jobs\TranslateLocaleJob` runs on the queue (the `ai` queue, same connection as agent
runs), so **the queue cron has to be running** or nothing happens. See [QUEUE.md](QUEUE.md).

It takes the keys that have nothing to render in the target language, batches them against
both `config('ai.limits.max_context_tokens')` and the provider's own `max_tokens` — the reply
is the same text again, and a batch that cannot fit in it comes back cut off mid-object —
sends each batch through `App\Ai\Providers\ProviderFactory` with the placeholder rules in the
instruction, and stores what survives validation. Progress appears on the page while it runs.

One dispatch attempts at most 400 lines, so a click cannot spend an unbounded amount. Press it
again for the next 400.

### What it validates

Every returned line is checked before it is stored. Discarded, not stored:

- anything that is not a string, or is empty;
- anything that lost a placeholder the English needs;
- an entire batch whose reply would not decode as JSON — counted as discarded rather than
  treated as a failure, so one unusable answer does not throw away the batches that worked.

The refusal check is repeated **between every batch**, so a kill switch engaged while a pass
is in flight stops it at the next batch. Lines already written stay written.

### Why its output starts unreviewed

Everything the model writes is stored with `is_reviewed = false`, and there is no path in that
job that sets the flag. Machine translation of interface strings is good and it is not
reliable: it does not know that "Key" is a project's short code rather than something you
unlock a door with, that "Board" is a Kanban board, or which of six words for "task" this
product has been using consistently for the last two hundred strings. It is a first draft.

The **Needs review** filter is therefore the queue of everything nobody has read, and ticking
**Reviewed** is a person saying they have. Keeping that flag honest is the whole point: a
first pass you can see is worth having, and a first pass silently indistinguishable from
reviewed work is worse than no first pass at all.

### What it does not do

There is no `ai_runs` row and no entry in `ai_usage_daily`. Both are workspace-scoped, and
every column on `ai_runs` describes a tool loop; a translation pass is neither. It is recorded
in `audit_logs` as `admin.translations_ai_requested`, with the language and the limit. Token
counts for these calls are therefore not in **Admin → AI → Usage**.

---

## 6. Working with an outside translator

The export and import panel is under **Export and import** on the translation screen, and it
writes and reads exactly the document `php artisan lang:export` and `lang:import` use, so a
file can make the round trip through either.

**Export everything** gives you every key with whatever this language currently has, and
**Export what is untranslated** gives you only the gaps. Both keep every key present — a file
containing only the finished lines is of no use to the person whose job is the rest.

The shape is one object per catalogue, `*` being the literal strings:

```json
{
  "*": { "Create project": "Projekt anlegen" },
  "actions": { "tasks.copy_of": "Kopie von :title" }
}
```

A flat map of strings is also accepted, and is read as the catalogue currently selected on
screen — which is what somebody who exported one group and sent it to a translator would
expect back. A document mixing both shapes is refused rather than guessed at.

### Import is previewed, never immediate

Uploading a file shows what it *would* do and writes nothing:

| Column | Meaning |
|---|---|
| **New** | This language had nothing for the key |
| **Replaced** | It had something different |
| **Unchanged** | Identical to what is already stored |
| **Cleared** | The file has an empty string, so the line falls back to English |
| **Not in the product** | The key is not in this build — skipped, because a row nothing renders could never appear. A large number here means the file was exported from a different version |
| **Refused** | The translation lost a placeholder. Listed individually, and never written |

**Mark every imported line as reviewed** is off by default. Turn it on only when a person you
trust produced the file; otherwise the lines arrive unreviewed and show up under **Needs
review**, which is the right default for anything that arrived from outside.

Apply or discard. Nothing is written until you apply.

### The equivalent commands

```bash
php artisan lang:scan                          # rebuild lang/en.json from the source
php artisan lang:sync                          # a row per key for every enabled language
php artisan lang:missing de                    # what German still cannot say
php artisan lang:export de --output=de.json    # send this to a translator
php artisan lang:export de --missing           # only the gaps
php artisan lang:import de de.json --dry-run   # what it would do
php artisan lang:import de de.json             # do it
```

`lang/en.json` is **generated**. Do not hand-edit it; run `lang:scan` after adding a string to
the product, or the string will render English in every other language.

---

## 7. Right to left: what is and is not covered

Arabic ships translated — all 4,209 catalogue keys — so right-to-left is tested against real
sentences of real length rather than against English standing in for them. A layout that
holds with `Create project` in the button and breaks with `إنشاء مشروع` in it is a layout
nobody had actually looked at.

### What is covered

**Direction is data, resolved per request.** It is a column on `locales` rather than a
hard-coded list of RTL codes — which is wrong the first time somebody adds a language that is
not on the list. `SetLocale` shares it with every view, and the app, guest, print, error and
installer layouts all put it in `dir` on `<html>`.

**It survives a Livewire round trip.** `SetLocale` is registered as Livewire persistent
middleware, so `POST /livewire/update` — which carries the `web` group, not the middleware of
the page the component is on — resolves the same language *and the same direction*. Without
that, an Arabic user's second interaction with any component would come back in English and
laid out left-to-right inside a right-to-left page.

**The stylesheet works on the inline axis.** `resources/css/app.css` defines `--flow` as the
sign of that axis, so one declaration serves both directions, and adds the logical utilities
Tailwind does not have: `slide-from-start` / `slide-from-end` for panels that enter from an
edge, `origin-top-start` / `origin-top-end` for menus, and `nudge-inline` for hover movement.
The product's own views use logical spacing and inset utilities throughout.

**Directional glyphs mirror, by hand.** `flip-rtl` mirrors a chevron that means "onward along
the line" — next page, drill in, collapse toward the edge. It is applied at each site rather
than to every chevron in the icon set, because a chevron that means "open downward" must not
mirror.

**Numbers and identifiers are isolated.** A task key, a timestamp, a duration or a signed
number inside an Arabic sentence would otherwise be reordered by the bidi algorithm. The
`x-ui.bidi` component and the `bidi-isolate` utility keep those runs intact.

`App\Support\Bidi` is the same isolation as characters (U+2066…U+2069) rather than as
markup, for the places markup cannot reach: a value handed to `__()` as a `:placeholder`, an
`aria-label`, a `wire:confirm` sentence, and a run buried inside text somebody else wrote.
`Bidi::ltr()` is the component's twin, `Bidi::auto()` is `dir="auto"`, and `Bidi::numbers()`
wraps only the numeric runs inside a string this product did not write — a tool argument, a
model's own summary, a file name.

That third one exists because of a rule most people meet only once. An Arabic **letter**
earlier in the same paragraph turns every digit after it from a European number into an
*Arabic* number (UAX #9, W2), which takes the hyphens between them out of the number and
hands them to the paragraph: `2026-09-02` in an Arabic sentence renders `02-09-2026`, and
`خطة 2026-09-30.pdf` renders `خطة pdf.30-09-2026`. It is not fixed by putting the sentence in
an `ltr` container, because the rule looks at the preceding letter rather than at the
direction. Isolating the run is the only thing that fixes it.

**Fonts.** Noto Sans Arabic ships with the build and sits after Inter in the stack, so Latin
still resolves to Inter first.

**Text a person typed carries `dir="auto"`; text the product wrote does not.** The interface
is in the reader's language and takes the page direction. A task title, a description, a
comment, a wiki page and a workspace description are in whatever language the author was
writing in, and that is not a property of the reader — so those take their direction from
their own first strong character.

Two things go wrong without it, and both are silent. An English sentence inside an RTL page
has its closing full stop resolved to the paragraph direction and printed at the wrong end:
*Notes are in the project wiki.* renders as *.Notes are in the project wiki*. And a title
short enough to fit in English overflows in Arabic, at which point `truncate` puts the
ellipsis at the inline end of the **row** rather than the end of the **title**, so an English
title inside an Arabic list loses its beginning — `…ild the page templates` — which is the
half that identified it.

`dir="auto"` fixes both, and fixes the mirror case for free: an Arabic comment on an English
installation reads right-to-left inside a left-to-right page, which is what it should do.
It is applied at the call site rather than to the input and prose components, because those
same components also carry slugs, hex colours, numbers and dates, where the direction is not
the content's to decide.

**Charts and the timeline mirror, and the plot frame does not.** An SVG is a coordinate
space, not a paragraph: every `x` is measured from the left of the viewBox, while
`text-anchor="end"` resolves against the inline direction — so simply letting `rtl` reach a
plot moves none of the geometry and only anchors each axis label on the far side of its own
text, where the bars then paint over it. So each `<svg>` carries its own `dir="ltr"`, the
arithmetic in `resources/views/components/chart/*` is done once in that one frame, and each
coordinate is reflected through the viewBox on the way out. In Arabic the bars grow from the
right, the category axis reads right to left, the value axis moves to the right with it, and
a horizontal bar's label sits to the right of its bar. The ring chart runs counter-clockwise
for the same reason. `scaleX(-1)` over the whole plot would have been one line and would have
mirrored the lettering with it.

Text inside the plot is not reflected. Each label is written in the page's direction and
anchored in it, because `text-anchor` resolves against the inline direction of the text
element — which is what makes the logical anchors identical in both. A figure is pinned to
`ltr` and has its anchor flipped instead: an axis is read as a number. The heading, the
legend, the tooltip and the accessible table are ordinary HTML and follow the page as they
always did.

**The timeline runs in reading order.** Earlier work is on the right in Arabic and later work
extends to the left, the row headers stick to the right edge, the today marker and the
horizontal scroll mirror with them, and Alt + ← still moves a focused bar the way the arrow
points. Every offset the component produces is an offset from the *inline start* and is spent
on `inset-inline-start`, so the browser picks the side. The exception is the dependency
overlay, which is an SVG: those paths are reflected in PHP from the direction `SetLocale`
resolved, and the arrowheads follow because `orient="auto"` takes its angle from the path.

Two things this does not do. Figures stay in Western digits and left to right — there are no
Arabic-Indic numerals — because that is a numbering-system choice rather than a direction
one. And a Latin label long enough to be cut short puts its ellipsis at the Arabic end of the
run rather than beside the character it cut, which is ordinary bidi: the alternative is to
resolve each label's direction from its own content, and that makes the anchor depend on the
content too, which is how a label ends up on the wrong side of its own tick.

**The installer can be read in Arabic, before there is a database to say so.** The wizard runs
before `migrate`, so `locales` does not exist and `SetLocale` had nothing to resolve: every
screen rendered in `config('app.locale')` with no direction shared at all, which meant an
installation whose `.env` already said `ar` came out as Arabic text laid out left to right —
worse than English, because it looks like somebody tried. `Locale::SHIPPED` is the list of
catalogues inside the release, and it is also exactly what the first seed writes into
`locales`, so it is the one list both sides can agree on. The installer shell offers it as a
picker on every step, not only the first: nine screens is a long way to walk in a language
you cannot read, and the choice is stored in the session — a form the person at the keyboard
submitted, not a `?lang=` a link could set for them. What they pick also becomes the default
offered on the Application step, so the wizard's language and the installation's language are
one answer rather than two.

**The web app manifest is served, not shipped.** `site.webmanifest` used to be a file in
`public/`, which meant the name under the icon on an Arabic reader's home screen was English
and the document carried neither `lang` nor `dir`. A file in `public/` cannot be fixed: with
the document root pointed at `public/` the web server answers it before PHP is reached. It is
now `App\Http\Controllers\WebManifestController` on the same path — which is why
`EnsureInstalled` and `MaintenanceMode` have always listed `site.webmanifest` among the paths
they let through.

**The error pages and the maintenance page speak the reader's language.** They are
self-contained — no compiled asset, no layout inheritance, no database read — because an
error page has to render when the rest of the application cannot, and that is exactly why
every string on them was English until now: `__()` looked like a dependency. It is not.
`DatabaseTranslationLoader` is written never to throw, so the catalogue answers whether or
not there is a database, and the pages carry their own `@font-face` for Noto Sans Arabic.

The maintenance page needed one more thing. `MaintenanceMode` answers 503 instead of calling
the rest of the stack, so anything behind it never runs — and `SetLocale` was behind it. It
is now named in the priority list between the two gates: `EnsureInstalled` has already
confirmed there is a `locales` table to read, and nothing asks an unconfigured server for a
database it does not have. The message itself is no longer seeded, either. A seeded row is a
fixed English sentence, and the middleware prefers a stored value over its own fallback, so
seeding one replaced a translated default with an untranslatable row nobody chose.

**The administration panel is Arabic and right to left.** A Filament panel does not run
through the `web` group — it assembles its own middleware stack — so `SetLocale` is named in
that stack explicitly, after the session and the authentication that populates it, because
the language it resolves is the signed-in administrator's. Filament reads its direction from
one translation key rather than from the `dir` Planvio's own layouts set, and
`lang/vendor/filament-panels/{en,ar}/layout.php` is that key. Filament's own strings ship
translated inside the packages, so nothing has to be published; Planvio's resource labels,
page titles, table columns, form fields and actions are ordinary `__()` calls.

What the panel does *not* get for free is typography. It has its own Tailwind build and its
own copy of Inter, so neither the Arabic face nor the three corrections in
`resources/css/app.css` reach it: left alone it set Arabic in whatever the operating system
offered, at Latin leading, with the negative tracking Filament puts on every heading.
`resources/views/filament/partials/arabic-typography.blade.php` carries the same rules and the
same `@font-face`, injected through a render hook and only when the panel is actually
rendering in Arabic. The two panel pages that write their column headers as inline styles —
inline beats every selector — decide their own tracking in PHP for the same reason.

**Email arrives in the reader's language, not the sender's.** `App\Models\User` implements
`HasLocalePreference`, which is the only thing Laravel asks before it builds any channel of a
notification. Without it a queued notification renders in whatever locale the *worker* has —
`config('app.locale')` — so an Arabic account received English mail linking to an Arabic
product, and no test could see it because the suite's users are English. One notification
sent to five people is now rendered five times, each in the language that person chose, and
that is also the language the inbox row is stored in. `preferredLocale()` applies the same
rule `SetLocale` applies to a page: `users.locale` is an ordinary column, and a code left
behind by a language that was later switched off is not permission to render in it.

**Email is direction-aware, the hard way.** A queued notification renders in a worker with no
request, so it cannot read the direction `SetLocale` shares with a view — it resolves it from
`Locale::directionFor()` instead, which falls back to a short list of RTL languages when the
`locales` table cannot be read at all. The consequences are then computed in PHP rather than
expressed in CSS, because a mail client has no logical properties: Outlook renders through
Word, which supports neither `padding-inline-start` nor direction inherited into a nested
table, and this shell is nested tables all the way down. So `dir` is set on `<html>`, on
`<body>` and on every table, the near and far sides of the header are chosen as *physical*
sides before the template runs, `align=` gets the reading edge, and letter-spacing is reset
to `normal` for RTL. The font stack is a system one — mail clients block webfonts, so Tahoma
carries Arabic on Windows rather than the vendored Noto.

### What is not covered

**Email carries its own date shape.** Mail *is* direction-aware — see §7's covered list — but
a notification's meta rows are written with `translatedFormat('j M Y')` rather than through
`Formats`, so they read `9 سبتمبر 2026` whatever `workspaces.date_format` says. That is a
decision, not an omission: a named month is legible to somebody reading in a mail client
three weeks later with no workspace around it, and `l j M Y` on a due-soon nudge carries the
weekday, which no `date()` format string the settings screen offers would include.

**Default statuses, tags and project stages are translated once, at creation, and are then
just data.** A new workspace gets `Backlog`, `To Do`, `In Progress`, `Planning`, `Active`,
`Urgent`, `Client` and the rest from `config('planvio.defaults')`, and
`App\Support\DefaultNames` renders each through a `defaults.*` key on the way in — so a
workspace created in Arabic opens with an Arabic board rather than English column headings
nobody chose.

They are ordinary editable rows from that moment on, and nothing re-translates them later.
That is the part to understand before switching a workspace's language: the columns keep the
names they were created with, because re-translating would overwrite whatever a colleague
renamed them to, and a rename is a decision where a translation is only a default.

The `defaults.*` namespace exists because a bare `__('Blocked')` would resolve against the
shared JSON catalogue, where *Blocked* already belongs to the CSV import wizard and means a
row that was refused — مرفوضة, which is right for an import report and wrong for a task
waiting on something. One English word, two meanings, one catalogue that cannot hold both.

**The system project templates are English rows.** `SystemProjectTemplates` writes the shipped
templates — their names, descriptions, milestones and starter tasks — as ordinary rows with a
null `workspace_id`, in English, and nothing translates them later. They are the same kind of
thing as a workspace's own template: editable, permanent, and a rename is a decision rather
than a default. An Arabic installation therefore sees English template names in
**Admin → Project templates** and in the "start from a template" picker until somebody edits
them. Translating them at seed time would be worse for the reason the statuses paragraph
gives, with one addition: a template's `definition` is a JSON tree of task titles, and half a
translated tree is not a thing anybody can review.

**Anything written into a record at the time it happened stays in that language.** An
activity line, an audit-log description and a stored notification row are rendered once, when
the event occurred, in the language of the person it was rendered for — and are then text in
a column. Switching a language afterwards does not rewrite history, deliberately: the
alternative is a log that reads differently depending on who opens it, which is the one
property a log must not have.

**The two-factor challenge renders in the installation's default language.** By the time that
screen appears the password has been accepted and the account *is* known — but it is known
only as an id parked in the session, and the visitor is still a guest. `SetLocale` resolves a
language from the signed-in person, and there is not one yet. So somebody whose account is
Arabic on an English installation types their six digits on an English screen, exactly as
they did on the sign-in screen a moment earlier. It is one screen, it is consistent with the
one before it, and reading a locale out of a half-authenticated session to fix it would put a
stored preference on the wrong side of the authentication boundary.

**The Application step takes a language code as free text.** The installer's picker offers
the catalogues this release ships, but the field that decides `APP_LOCALE` accepts any
two-letter code — so an installation can be pointed at a language it has no catalogue for,
and every string will render the English it was written in. That is deliberate: a translator
who has dropped their own `lang/de.json` into the tree before installing should be able to
name it.

**The audit was empirical, not exhaustive.** Every screen of the product, the installer and
the signed-out pages were rendered in Arabic in a real browser at 1440×900 and compared with
the same screen in English; every GET route was loaded twice, once per language, and answered
identically. That is evidence, not a proof about every viewport, every component state and
every combination of filters. If you find a component that lays out mirrored-wrong, it is a
bug and it is fixable in one place; it is not a claim this document made.

---

## 8. Typography, numbers and dates

Direction is where a line starts. It is not what a language looks like, and Arabic set at
Latin metrics is wrong in three ways that mirroring does not touch. This section is the
decision Planvio made about each, so that it is one decision rather than one per call site.

### The three corrections

They live in `resources/css/app.css`, in the block headed *Arabic typography*.

| | Latin | Arabic |
|---|---|---|
| **Leading** | 1.5 body, and the ratio Tailwind ships for each size | 1.6–1.8 body, tapering to 1.39 at `text-3xl` |
| **Letter spacing** | `tracking-wide`/`wider`/`widest` on 145 labels, plus a −0.011em optical tightening on headings | all zero |
| **Weight** | `font-semibold` is 600, `font-bold` is 700 | 550 and 650 |

Arabic has no x-height to sit on: the alef and lam run well above the band Latin occupies
and the tails of ya, jim and ayn well below it, with vowel marks drawn outside that again.
At 1.5 the descenders of one line meet the marks of the next. It is also cursive, so
letter-spacing is applied to the *joins* — tracking a word out does not open it up the way
it does a Latin word, it stretches the connecting strokes and pulls the word into pieces.
And it puts more ink on the line than Inter does at the same nominal weight, so 600 beside
Inter's 600 reads as the bolder of the two rather than as the same emphasis.

Arabic also has no letter case, so `text-transform: uppercase` is neutralised with them.
Not because it does anything to Arabic — it does nothing at all — but because the label
next to it may be a project name, a tag or a task key somebody typed in English, and that
one is shouted while its neighbours are not.

**The hook is `:lang(ar)`, not `[dir="rtl"]`.** Script and direction are different facts
about a language. Hebrew reads right to left and is neither cursive nor caseless: it wants
every rule in §7 and none of these. `:lang()` matches on subtag boundaries, so a rule
written for `ar` covers `ar`, `ar-EG` and `ar-Arab-SA`.

**Each is made by redefining the variable Tailwind's own utility reads**, on the root
element, rather than by out-specifying that utility. `text-sm` compiles to
`line-height: var(--tw-leading, var(--text-sm--line-height))`, `tracking-wider` to
`letter-spacing: var(--tracking-wider)` and `font-semibold` to
`font-weight: var(--font-weight-semibold)` — so one rule reaches every sized run and every
tracked label in the product, is inherited by Livewire fragments swapped in after load,
costs a Latin install nothing, and never has to win a specificity argument.

Two kinds of run are Latin whatever the page language is — a currency code, a person's
initials — and the `uppercase-latin` utility is the opt-out for those.

The installer and the error page carry their own copy of the three rules, in their own
`<style>` blocks, because neither can load the compiled stylesheet: both have to render on
a server where `npm run build` has never been run. The error page also gained the
`@font-face` for Noto Sans Arabic that it was missing, which is worth having on exactly the
page somebody sees when the build is broken.

### The font

`public/fonts/noto-sans-arabic/noto-sans-arabic.woff2` is a variable face with a real
`wght` axis of 100–900, so the 550 above is interpolated rather than synthesised. Its
coverage was read out of the file rather than assumed: 1,226 code points, and of the five
ranges the `@font-face` declares, the only assigned character it does not carry is U+08E2
ARABIC DISPUTED END OF AYAH. Arabic-Indic digits (both sets), the Arabic comma, semicolon,
question mark, full stop, percent sign, decimal and thousands separators, tatweel, the
tashkeel marks and both presentation-form blocks including the lam-alef ligatures are all
present. Every one of the 47 distinct Arabic-block code points used by `lang/ar.json` and
`lang/ar/*.php` is in it, so nothing in the shipped catalogue renders as tofu.

### Digits are Latin. Everywhere, in every language

Arabic locales do not agree with each other: Egypt and the Gulf commonly set Arabic-Indic
(١٢٣), the Maghreb sets Latin (123). There is no answer that is simply correct, so this is a
decision — Latin — and the reasons are about this product rather than about the script.

A task key is `WEB-142` in every language, because it is an identifier and half of it is
Latin already; `WEB-١٤٢` identifies the same task with a different string, and the first
person to paste one into a search box finds nothing. The same figures go out to CSV, to the
API and into an email, and survive none of those round trips reliably in Arabic-Indic — a
budget that reads one way on screen and another in the export is a reconciliation problem,
not a typographic preference. And the real failure mode is neither choice but both at once:
a count in Arabic-Indic beside a duration in Latin.

`App\Support\Formats` is where that is written down, and `Formats::number()` and
`Formats::decimal()` are what the product's views call. What makes the rule hold is that
nothing reaches for a locale-aware formatter:

- `number_format()` is documented as locale-independent and always emits ASCII digits.
- `Illuminate\Support\Number` and `IntlDateFormatter` would both emit `١٬٢٣٤` under an `ar`
  locale without anybody asking for it. Neither is used anywhere in the application.
- Carbon's Arabic-Indic numerals sit under `alt_numbers`, reached only by the `OD`, `OM`,
  `OY`, `OH`, `Oh`, `Om` and `Os` tokens of `isoFormat()` and by `diffForHumans()` with the
  `altNumbers` option. Planvio uses none of them, and the plain `ar` catalogue it resolves
  to does not define `alt_numbers` at all.

`tests/Feature/Localisation/ArabicFormattingTest.php` holds that line, by rendering pages in
Arabic and failing on any Arabic-Indic digit in the output.

### Months and weekdays are Arabic

The names are the part of a date that is language rather than notation, so they are
translated: **9 سبتمبر 2026** — not `9 September 2026`, and not `٩ سبتمبر ٢٠٢٦`. They come
from Carbon, and Carbon follows `App::setLocale()` because its own service provider —
registered by Composer package discovery, not by this application — listens for Laravel's
`LocaleUpdated` event. So a date follows `SetLocale` with nothing further to wire up, and
because that is a dependency on somebody else's provider being registered, and its failure
would be silent, `ArabicFormattingTest` asserts it directly.

The one thing to know when writing a view: `->format()` is PHP's own and answers in English
whatever the application locale is. `->translatedFormat()` and `->isoFormat()` are the
locale-aware pair. A date rendered with `format()` is the shape this bug takes.

An Arabic date also takes an Arabic comma, ، (U+060C), which `Formats` substitutes into the
format string for the two shipped formats that carry one.

### What `date_format` governs

`workspaces.date_format` holds a PHP `date()` format string chosen in **Settings → General**;
`locales.date_format` is the optional per-language override set in **Admin → Platform →
Languages**. `App\Support\Formats::pattern()` resolves them, most specific first — the
locale's, then the workspace's, then `planvio.defaults.workspace.date_format` from `.env`,
then `Y-m-d` — skipping each step that is blank rather than rendering nothing.

Planvio writes two shapes of date, and only the first is that setting's business.

**A date as a value** — a field, a table cell, a definition list, a report row — is written
in the resolved format, through `Formats::date()` and `Formats::dateTime()`. So an Arabic
workspace set to `D, j M Y` reads `أربعاء، 9 سبتمبر 2026` in the timesheet and the status
report, and one left on the default reads `2026-09-09` in both languages.

**A date inside a sentence** — "Due 9 Sep", "Overdue since 9 Sep", a board card, a due-date
pill — keeps Carbon's own short form at the call site. `Due 2026-09-09` is not a sentence,
and a prose date wants the month named and the year dropped when it is this year. Those
sites are already language-aware and are deliberately not routed through the setting.

Machine-readable dates are a third thing and are neither: the value of an
`<input type="date">`, a calendar cell's key, a timeline axis position and every column of a
CSV export stay ISO `Y-m-d` in every language, because they are read by a program.

**A chart axis tick** is the second shape, not the first: `9 سبت`, in the reader's language,
because a hundred and eighty full dates along one axis is unreadable at any setting. The
`sr-only` data table beside each chart repeats the *same* tick labels rather than the
workspace format, on purpose — it exists so a screen-reader user gets the figures the chart
is showing, and a table whose first column disagreed with the axis beside it would describe
a different chart.

### What `first_day_of_week` governs

The same shape, resolved by `Formats::weekStartsOn()`: `locales.first_day_of_week` for the
language being read, then `workspaces.week_starts_on`, then
`planvio.defaults.workspace.week_starts_on`, then Monday. `0` is Sunday through `6` for
Saturday. A blank locale value means *inherit*, which is what the admin form promises; `0`
means Sunday and is not blank, so the resolution is a range check rather than a truthiness
test. A value outside `0..6` is ignored rather than clamped, and the workspace answers
instead — a setting an administrator can see and correct beats a plausible-looking guess.

It reaches three screens and only three: the calendar grid, the timesheet week, and the
timeline's week ruler. All three are drawings, and which column a week opens in is a reading
preference — an Arabic reader who expects السبت first is not disagreeing with a colleague
about any fact, so it is right that the two of them see the same workspace laid out
differently.

`App\Services\DateResolver` and the AI tools that lean on it deliberately do **not** come
through it. "What is due next week" returns a set of tasks, and a set of tasks cannot depend
on the language the question was typed in — two people in one workspace would get different
lists from the same words and only one of them would be right. Those paths read
`workspaces.week_starts_on` directly, which is the workspace's single answer to *when does
our week start*.

---

## 9. Where things live

| | |
|---|---|
| `locales` table | The languages offered. `App\Models\Locale` |
| `translations` table | This installation's own wording. Keyed `(locale, group, key_hash)` |
| `App\Http\Middleware\SetLocale` | Resolution per request, and `$textDirection` — including the wizard, before `locales` exists |
| `App\Models\Locale::SHIPPED` | The catalogues inside this release. What the installer offers and what the first seed writes |
| `App\Http\Controllers\Install\LanguageController` | The wizard's own language picker |
| `App\Models\User::preferredLocale()` | The language a notification addressed to this person renders in |
| `App\Http\Controllers\WebManifestController` | `site.webmanifest`, with the reader's `lang` and `dir` |
| `resources/views/filament/partials/arabic-typography.blade.php` | The three corrections and the Arabic face, for `/admin` |
| `lang/vendor/filament-panels/{en,ar}/layout.php` | The one key Filament reads its direction from |
| `App\Services\Translation\DatabaseTranslationLoader` | Files with rows merged over them |
| `App\Services\Translation\TranslationRepository` | Reads and writes rows; owns the cache version stamp |
| `App\Services\Translation\TranslationCatalogue` | Recovers every translatable key from the source |
| `App\Services\Translation\Placeholders` | The `:name` rule, used by the editor, the importer and the AI job |
| `App\Services\Translation\TranslationImporter` | Parse, preview, apply |
| `App\Jobs\TranslateLocaleJob` | The AI first pass |
| `App\Filament\Resources\Locales\LocaleResource` | Admin → Platform → Languages |
| `App\Filament\Pages\Translations` | Admin → Platform → Translations |
| `App\Support\Formats` | Dates and numbers: the format string in force, the week start in force, Latin digits, Arabic month names |
| `lang/README.md` | Which of the three places a new string belongs in |
